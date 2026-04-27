import os
import secrets
import hashlib
from uuid import UUID
from typing import Optional, Dict, Any, Tuple
from datetime import datetime, timezone

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, update, and_
from sqlalchemy.exc import IntegrityError
from fastapi import HTTPException, status

from src.models.database.user import User
from src.services.audit_service import AuditService


class AdminService:
    """Service to manage admin users with single admin constraint"""

    def __init__(self, audit_service: AuditService):
        self.audit_service = audit_service

    async def get_active_admin(self, db: AsyncSession) -> Optional[User]:
        """Get the currently active admin"""
        stmt = select(User).where(User.role == "admin", User.is_active == True)
        result = await db.execute(stmt)
        return result.scalar_one_or_none()

    async def is_admin_exists(
        self, db: AsyncSession, include_inactive: bool = False
    ) -> bool:
        """Check if any admin exists"""
        stmt = select(User).where(User.role == "admin")
        if not include_inactive:
            stmt = stmt.where(User.is_active == True)
        result = await db.execute(stmt)
        return result.first() is not None

    async def create_first_admin(
        self,
        db: AsyncSession,
        email: str,
        name: str,
        password: Optional[str] = None,
        api_key: Optional[str] = None,
    ) -> Tuple[User, str]:
        """
        Create the first admin user (only allowed if NO admin exists at all)
        This is typically called during initial system setup
        """

        # Check if any admin already exists (including inactive)
        if await self.is_admin_exists(db, include_inactive=True):
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail="Admin already exists. Cannot create another admin. Use admin creation token or transfer role.",
            )

        # Generate API key if not provided
        if not api_key:
            api_key = self._generate_api_key()

        # Hash the API key
        api_key_hash = self._hash_api_key(api_key)

        # Create admin user
        admin = User(
            email=email.lower(),
            name=name,
            role="admin",
            api_key_hash=api_key_hash,
            is_active=True,
            created_at=datetime.now(timezone.utc),
        )

        try:
            db.add(admin)
            await db.commit()
            await db.refresh(admin)
            print("\n\npassed here\n\n")

            # Log admin creation
            await self.audit_service.log_action(
                action="create_first_admin",
                actor_role="system",
                actor_id="initial_setup",
                details={
                    "admin_email": email,
                    "admin_name": name,
                    "method": "initial_setup",
                },
            )

            # Return admin with plaintext API key (only time it's returned)
            return admin, api_key

        except IntegrityError as e:
            await db.rollback()
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Failed to create admin. Constraint violation.",
            )

    async def create_admin_with_token(
        self,
        db: AsyncSession,
        email: str,
        name: str,
        creation_token: str,
        expected_token: str,
    ) -> Tuple[User, str]:
        """
        Create a new admin using a one-time creation token
        This allows creating a new admin even when one exists (deactivates old one)
        """

        # Verify creation token
        if not secrets.compare_digest(creation_token, expected_token):
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Invalid creation token",
            )

        # Get current active admin
        current_admin = await self.get_active_admin(db)

        # Deactivate current admin if exists
        if current_admin:
            current_admin.is_active = False
            current_admin.last_login = datetime.now(timezone.utc)

        # Generate API key for new admin
        api_key = self._generate_api_key()
        api_key_hash = self._hash_api_key(api_key)

        # Create new admin
        new_admin = User(
            email=email.lower(),
            name=name,
            role="admin",
            api_key_hash=api_key_hash,
            is_active=True,
            created_at=datetime.now(timezone.utc),
        )

        try:
            db.add(new_admin)
            await db.commit()
            await db.refresh(new_admin)

            # Log admin creation with token
            await self.audit_service.log_action(
                action="create_admin_with_token",
                actor_role="system" if not current_admin else "admin",
                actor_id=str(current_admin.id) if current_admin else "token_creation",
                details={
                    "new_admin_email": email,
                    "new_admin_name": name,
                    "previous_admin_deactivated": (
                        str(current_admin.id) if current_admin else None
                    ),
                    "method": "creation_token",
                },
            )

            return new_admin, api_key

        except IntegrityError as e:
            await db.rollback()
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Failed to create new admin",
            )

    async def create_admin_as_current_admin(
        self,
        db: AsyncSession,
        email: str,
        name: str,
        current_admin_id: UUID,
        deactivate_self: bool = False,
    ) -> Tuple[User, str]:
        """
        Create a new admin while being an existing admin
        Current admin can choose to deactivate themselves or keep both (temporary)
        """

        # Verify current admin exists and is active
        stmt = select(User).where(
            User.id == current_admin_id, User.role == "admin", User.is_active == True
        )
        result = await db.execute(stmt)
        current_admin = result.scalar_one_or_none()

        if not current_admin:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail="Only active admins can create new admins",
            )

        # Check if target email already exists
        stmt = select(User).where(User.email == email.lower())
        result = await db.execute(stmt)
        existing_user = result.scalar_one_or_none()

        if existing_user:
            if existing_user.role == "admin":
                raise HTTPException(
                    status_code=status.HTTP_409_CONFLICT,
                    detail="User is already an admin",
                )
            else:
                # Promote existing user to admin
                user_to_promote = existing_user
                api_key = self._generate_api_key()
                api_key_hash = self._hash_api_key(api_key)

                user_to_promote.role = "admin"
                user_to_promote.api_key_hash = api_key_hash
                user_to_promote.is_active = True

                new_admin = user_to_promote
        else:
            # Create new user as admin
            api_key = self._generate_api_key()
            api_key_hash = self._hash_api_key(api_key)

            new_admin = User(
                email=email.lower(),
                name=name,
                role="admin",
                api_key_hash=api_key_hash,
                is_active=True,
                created_at=datetime.now(timezone.utc),
            )
            db.add(new_admin)

        # Handle current admin's status
        if deactivate_self:
            current_admin.is_active = False
            current_admin.last_login = datetime.now(timezone.utc)

        try:
            await db.commit()
            await db.refresh(new_admin)

            # Log admin creation
            await self.audit_service.log_action(
                action="create_admin_by_admin",
                actor_role="admin",
                actor_id=str(current_admin_id),
                details={
                    "new_admin_email": email,
                    "new_admin_name": name,
                    "current_admin_deactivated": deactivate_self,
                    "was_existing_user": existing_user is not None,
                },
            )

            return new_admin, api_key

        except IntegrityError as e:
            await db.rollback()
            raise HTTPException(
                status_code=status.HTTP_409_CONFLICT,
                detail="Failed to create new admin. Constraint violation.",
            )

    async def create_super_admin_emergency(
        self,
        db: AsyncSession,
        email: str,
        name: str,
        emergency_code: str,
        system_secret: str,
    ) -> Tuple[User, str]:
        """
        EMERGENCY ONLY: Create an admin even if one exists
        Requires special emergency code and system secret
        Use this if all admins are locked out
        """

        # Verify emergency code (this should be set in environment variables)
        expected_code = os.getenv("EMERGENCY_ADMIN_CODE", "NOT_SET")
        expected_secret = os.getenv("SYSTEM_SECRET", "CHANGE_ME")

        if not secrets.compare_digest(emergency_code, expected_code):
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED,
                detail="Invalid emergency code",
            )

        if not secrets.compare_digest(system_secret, expected_secret):
            raise HTTPException(
                status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid system secret"
            )

        # Deactivate ALL existing admins
        stmt = (
            update(User)
            .where(User.role == "admin")
            .values(is_active=False, last_login=datetime.now(timezone.utc))
        )
        await db.execute(stmt)

        # Generate API key
        api_key = self._generate_api_key()
        api_key_hash = self._hash_api_key(api_key)

        # Create emergency admin
        emergency_admin = User(
            email=email.lower(),
            name=name,
            role="admin",
            api_key_hash=api_key_hash,
            is_active=True,
            created_at=datetime.now(timezone.utc),
        )

        db.add(emergency_admin)
        await db.commit()
        await db.refresh(emergency_admin)

        # Log emergency creation (critical!)
        await self.audit_service.log_action(
            action="emergency_admin_creation",
            actor_role="emergency_system",
            actor_id="emergency_procedure",
            details={
                "admin_email": email,
                "admin_name": name,
                "previous_admins_deactivated": True,
            },
        )

        return emergency_admin, api_key

    def _generate_api_key(self) -> str:
        """Generate a secure API key"""
        return f"cert_admin_{secrets.token_urlsafe(32)}"

    def _hash_api_key(self, api_key: str) -> str:
        """Hash API key for storage"""
        return hashlib.sha256(api_key.encode()).hexdigest()

    async def transfer_admin_role(
        self,
        db: AsyncSession,
        current_admin_id: UUID,
        new_admin_email: str,
        actor_id: str,
    ) -> Dict[str, Any]:
        """
        Transfer admin role from current admin to another user.
        This deactivates the current admin and creates a new one.
        """

        # Get current admin
        stmt = select(User).where(
            User.id == current_admin_id, User.role == "admin", User.is_active == True
        )
        result = await db.execute(stmt)
        current_admin = result.scalar_one_or_none()

        if not current_admin:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND, detail="Active admin not found"
            )

        # Deactivate current admin
        current_admin.is_active = False

        # Find or create target user
        stmt = select(User).where(User.email == new_admin_email)
        result = await db.execute(stmt)
        new_admin = result.scalar_one_or_none()

        if not new_admin:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND,
                detail=f"User with email {new_admin_email} not found",
            )

        # Promote to admin
        new_admin.role = "admin"
        new_admin.is_active = True

        try:
            await db.commit()

            # Log transfer
            await self.audit_service.log_action(
                action="transfer_admin",
                actor_role="admin",
                actor_id=str(actor_id),
                details={
                    "from_admin": str(current_admin.id),
                    "from_email": current_admin.email,
                    "to_admin": str(new_admin.id),
                    "to_email": new_admin_email,
                },
            )

            return {
                "message": "Admin role transferred successfully",
                "previous_admin_deactivated": str(current_admin.id),
                "new_admin_activated": str(new_admin.id),
            }

        except IntegrityError:
            await db.rollback()
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="Failed to transfer admin role",
            )

    async def deactivate_admin(
        self, db: AsyncSession, admin_id: UUID, actor_id: str, force: bool = False
    ) -> Dict[str, Any]:
        """Deactivate an admin (only allowed if there's another admin to take over)"""

        # Get admin to deactivate
        stmt = select(User).where(User.id == admin_id, User.role == "admin")
        result = await db.execute(stmt)
        admin = result.scalar_one_or_none()

        if not admin:
            raise HTTPException(
                status_code=status.HTTP_404_NOT_FOUND, detail="Admin user not found"
            )

        if not admin.is_active:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="Admin is already deactivated",
            )

        # Check if this is the only admin
        stmt = select(User).where(
            User.role == "admin", User.is_active == True, User.id != admin_id
        )
        result = await db.execute(stmt)
        other_active_admins = result.scalars().all()

        if not other_active_admins and not force:
            raise HTTPException(
                status_code=status.HTTP_400_BAD_REQUEST,
                detail="Cannot deactivate the only active admin. Transfer role first or use force=True",
            )

        # Deactivate admin
        admin.is_active = False
        await db.commit()

        # Log deactivation
        await self.audit_service.log_action(
            action="deactivate_admin",
            actor_role="admin",
            actor_id=str(actor_id),
            details={"deactivated_admin": str(admin_id), "force": force},
        )

        return {
            "message": "Admin deactivated successfully",
            "admin_id": str(admin_id),
            "remaining_active_admins": len(other_active_admins),
        }

    async def get_admin_status(self, db: AsyncSession) -> Dict[str, Any]:
        """Get current admin status"""

        # Get active admin
        active_admin = await self.get_active_admin(db)

        # Get all admin users
        stmt = select(User).where(User.role == "admin")
        result = await db.execute(stmt)
        all_admins = result.scalars().all()

        return {
            "has_active_admin": active_admin is not None,
            "active_admin": (
                {
                    "id": str(active_admin.id),
                    "email": active_admin.email,
                    "name": active_admin.name,
                }
                if active_admin
                else None
            ),
            "total_admin_users": len(all_admins),
            "inactive_admins": [
                {
                    "id": str(admin.id),
                    "email": admin.email,
                    "name": admin.name,
                    "deactivated_at": admin.last_login,  # Use appropriate field
                }
                for admin in all_admins
                if not admin.is_active
            ],
        }

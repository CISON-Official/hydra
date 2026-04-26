import uuid
from datetime import datetime, timezone
from typing import Optional

from sqlalchemy import String, DateTime, Boolean, Text, event, UniqueConstraint
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, validates
from sqlalchemy.exc import IntegrityError

from src.models.database.certificate import Base


class User(Base):
    __tablename__ = "users"

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    email: Mapped[str] = mapped_column(
        String(255), unique=True, nullable=False, index=True
    )
    name: Mapped[str] = mapped_column(String(255), nullable=False)
    role: Mapped[str] = mapped_column(
        String(50), nullable=False
    )  # issuer, holder, verifier, admin
    api_key_hash: Mapped[str | None] = mapped_column(String(255))
    is_active: Mapped[bool] = mapped_column(default=True)
    created_at: Mapped[datetime] = mapped_column(
        DateTime(timezone=True), default=lambda: datetime.now(timezone.utc)
    )
    last_login: Mapped[datetime | None] = mapped_column(DateTime(timezone=True))

    # Add partial unique index for single admin constraint
    # __table_args__ = (
    #     # This ensures only ONE active admin can exist
    #     UniqueConstraint(
    #         'role',
    #         'is_active',
    #         name='uq_single_active_admin',
    #         postgresql_where="role = 'admin' AND is_active = TRUE"
    #     ),
    # )

    @validates("role")
    def validate_role(self, key, role):
        """Validate role values"""
        allowed_roles = {"issuer", "holder", "verifier", "admin"}
        if role not in allowed_roles:
            raise ValueError(f"Invalid role. Must be one of: {allowed_roles}")
        return role

    @validates("email")
    def validate_email(self, key, email):
        """Basic email validation"""
        if "@" not in email or "." not in email:
            raise ValueError("Invalid email format")
        return email.lower().strip()


# SQLAlchemy event listener to enforce single admin at insert/update time
@event.listens_for(User, "before_insert")
@event.listens_for(User, "before_update")
def enforce_single_admin(mapper, connection, target):
    """Prevent creating/activating a second admin user"""
    if target.role == "admin" and target.is_active:
        from sqlalchemy import text

        # Check if an active admin already exists
        query = text(
            """
            SELECT COUNT(*) FROM users 
            WHERE role = 'admin' 
            AND is_active = TRUE 
            AND id != :id
        """
        )

        result = connection.execute(query, {"id": target.id})
        count = result.scalar()

        if count > 0:
            # For new admin creation
            if target.id is None or target.id == uuid.uuid4():
                raise IntegrityError(
                    "Cannot create second admin user. Only one admin allowed.",
                    params={},
                    orig=None,  # type: ignore
                )
            # For reactivating an existing admin
            else:
                raise IntegrityError(
                    "Cannot activate second admin user. Deactivate existing admin first.",
                    params={},
                    orig=None,  # type: ignore
                )

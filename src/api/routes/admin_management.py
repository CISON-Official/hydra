import os
import secrets
from uuid import UUID
from typing import Optional

from pydantic import BaseModel, EmailStr, Field
from sqlalchemy.ext.asyncio import AsyncSession
from fastapi import APIRouter, Depends, HTTPException, BackgroundTasks, status

from src.api.dependencies.database import get_admin_service, get_db
from src.models.schemas.requests import (
    AdminCreateByAdminRequest,
    AdminCreateWithTokenRequest,
    EmergencyAdminCreateRequest,
    FirstAdminCreateRequest,
)
from src.models.schemas.responses import AdminCreateResponse
from src.services.admin_service import AdminService
from src.api.dependencies.auth import (
    require_role,
    get_current_user,
    require_admin_with_single_constraint,
)
from src.config import get_settings

router = APIRouter(prefix="/superadmin", tags=["Admin Management"])


@router.post("/setup/first", response_model=AdminCreateResponse)
async def create_first_admin_endpoint(
    request: FirstAdminCreateRequest,
    background_tasks: BackgroundTasks,
    db: AsyncSession = Depends(get_db),
    admin_service: AdminService = Depends(get_admin_service),
):
    """
    Create the FIRST admin user.
    Only works if NO admin exists in the system.
    Requires admin_secret from environment variables.
    """
    settings = get_settings()

    # Verify admin secret
    expected_secret = os.getenv("INITIAL_ADMIN_SECRET", settings.initial_admin_secret)
    if not secrets.compare_digest(request.admin_secret, expected_secret):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid admin creation secret",
        )

    try:
        result = await admin_service.create_first_admin(
            db=db, email=request.email, name=request.name
        )
        admin = result[0] if isinstance(result, tuple) else result
        api_key = (
            result[1] if isinstance(result, tuple) else getattr(result, "api_key", None)
        )

        # In production, send API key via email or secure channel
        # background_tasks.add_task(
        #     send_admin_credentials_email,  # Implement this
        #     email=request.email,
        #     api_key=api_key,
        #     name=request.name
        # )

        return AdminCreateResponse(
            message="First admin created successfully",
            admin_id=admin.id,
            email=admin.email,
            api_key=api_key,  # type: ignore
            warning="Save this API key immediately. It will not be shown again.",
        )

    except HTTPException:
        raise


@router.post("/create-with-token", response_model=AdminCreateResponse)
async def create_admin_with_token(
    request: AdminCreateWithTokenRequest,
    background_tasks: BackgroundTasks,
    db: AsyncSession = Depends(get_db),
    admin_service: AdminService = Depends(get_admin_service),
):
    """
    Create a new admin using a one-time creation token.
    This deactivates the current admin if one exists.
    """
    # Get expected token from cache/database
    expected_token = get_settings().creation_token

    try:
        result = await admin_service.create_admin_with_token(
            db=db,
            email=request.email,
            name=request.name,
            creation_token=request.creation_token,
            expected_token=expected_token,
        )

        admin = result[0] if isinstance(result, tuple) else result
        api_key = (
            result[1] if isinstance(result, tuple) else getattr(result, "api_key", None)
        )

        # Invalidate used token
        # await invalidate_creation_token(request.email)

        # background_tasks.add_task(
        #     send_admin_credentials_email,
        #     email=request.email,
        #     api_key=api_key,
        #     name=request.name
        # )

        return AdminCreateResponse(
            message="Admin created successfully. Previous admin deactivated if existed.",
            admin_id=admin.id,
            email=admin.email,
            api_key=api_key,  # type: ignore
            warning="Save this API key. Previous admin API keys are now invalid.",
        )

    except HTTPException:
        raise


@router.post("/create-by-admin", response_model=AdminCreateResponse)
async def create_admin_by_current_admin(
    request: AdminCreateByAdminRequest,
    background_tasks: BackgroundTasks,
    db: AsyncSession = Depends(get_db),
    admin_service: AdminService = Depends(get_admin_service),
    current_user=Depends(require_role(["admin"])),  # Requires existing admin
):
    """
    Create a new admin while being an existing admin.
    Current admin can choose to stay active or deactivate themselves.
    """
    try:
        result = await admin_service.create_admin_as_current_admin(
            db=db,
            email=request.email,
            name=request.name,
            current_admin_id=current_user["id"],
            deactivate_self=request.deactivate_self,
        )

        admin = result[0] if isinstance(result, tuple) else result
        api_key = (
            result[1] if isinstance(result, tuple) else getattr(result, "api_key", None)
        )

        message = "New admin created successfully."
        if request.deactivate_self:
            message += " Your admin privileges have been deactivated."

        # background_tasks.add_task(
        #     send_admin_credentials_email,
        #     email=request.email,
        #     api_key=api_key,
        #     name=request.name
        # )

        return AdminCreateResponse(
            message=message,
            admin_id=admin.id,
            email=admin.email,
            api_key=api_key,  # type: ignore
            warning="Save this API key. Share it securely with the new admin.",
        )

    except HTTPException:
        raise


@router.post("/emergency-create", response_model=AdminCreateResponse)
async def emergency_create_admin(
    request: EmergencyAdminCreateRequest,
    background_tasks: BackgroundTasks,
    db: AsyncSession = Depends(get_db),
    admin_service: AdminService = Depends(get_admin_service),
):
    """
    EMERGENCY ONLY: Create an admin even if one exists.
    Deactivates ALL existing admins.
    Requires emergency code and system secret from environment.
    """
    try:
        result = await admin_service.create_super_admin_emergency(
            db=db,
            email=request.email,
            name=request.name,
            emergency_code=request.emergency_code,
            system_secret=request.system_secret,
        )

        admin = result[0] if isinstance(result, tuple) else result
        api_key = (
            result[1] if isinstance(result, tuple) else getattr(result, "api_key", None)
        )

        # background_tasks.add_task(
        #     send_emergency_admin_alert,  # Implement this
        #     email=request.email,
        #     api_key=api_key
        # )

        return AdminCreateResponse(
            message="EMERGENCY: Admin created. All previous admins have been deactivated.",
            admin_id=admin.id,
            email=admin.email,
            api_key=api_key,  # type: ignore
            warning="CRITICAL: Secure this API key immediately. Previous admins have been locked out.",
        )

    except HTTPException:
        raise


# @router.post("/generate-creation-token")
# async def generate_creation_token(
#     email: EmailStr,
#     db: AsyncSession = Depends(get_db),
#     current_admin = Depends(require_admin_with_single_constraint)
# ):
#     """
#     Generate a one-time token for creating a new admin.
#     This allows controlled admin handover.
#     """
#     token = secrets.token_urlsafe(32)

#     # Store token in Redis with 1-hour expiry
#     await store_creation_token(email, token, ttl=3600)

#     # Log token generation
#     await audit_service.log_action(
#         action="generate_admin_creation_token",
#         actor_role="admin",
#         actor_id=str(current_admin["id"]),
#         details={"target_email": email}
#     )

#     return {
#         "message": "Creation token generated",
#         "token": token,
#         "expires_in_seconds": 3600,
#         "usage": f"Use this token with POST /admin/create-with-token"
#     }

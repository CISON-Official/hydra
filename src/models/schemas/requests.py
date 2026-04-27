from uuid import UUID
from typing import Optional
from datetime import datetime, timezone

from pydantic import BaseModel, Field, EmailStr, HttpUrl


class CertificateUploadRequest(BaseModel):
    holder_email: EmailStr
    holder_name: str
    certificate_title: str
    expires_at: datetime
    regenerate_qr_on_upload: bool = False


class CertificateUploadResponse(BaseModel):
    certificate_id: UUID
    qr_code_url: HttpUrl
    verification_url: str
    expires_at: datetime


class VerificationRequest(BaseModel):
    certificate_id: UUID
    hmac_signature: str


class VerificationResponse(BaseModel):
    session_token: str
    expires_in_seconds: int
    file_endpoint: str
    certificate_title: str
    holder_name: str
    expires_at: datetime
    is_valid: bool


class RevocationRequest(BaseModel):
    reason: str = Field(..., min_length=1, max_length=500)


class QRRegenerateResponse(BaseModel):
    new_qr_url: str
    old_qr_invalidated: bool


class FirstAdminCreateRequest(BaseModel):
    email: EmailStr
    name: str = Field(..., min_length=2, max_length=255)
    admin_secret: str = Field(..., description="Initial setup secret from environment")


class AdminCreateWithTokenRequest(BaseModel):
    email: EmailStr
    name: str = Field(..., min_length=2, max_length=255)
    creation_token: str = Field(..., description="One-time creation token")


class AdminCreateByAdminRequest(BaseModel):
    email: EmailStr
    name: str = Field(..., min_length=2, max_length=255)
    deactivate_self: bool = Field(
        False, description="Deactivate current admin after creation"
    )


class EmergencyAdminCreateRequest(BaseModel):
    email: EmailStr
    name: str = Field(..., min_length=2, max_length=255)
    emergency_code: str
    system_secret: str

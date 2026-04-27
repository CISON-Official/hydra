from uuid import UUID
from datetime import datetime
from typing import Optional, List

from pydantic import BaseModel, HttpUrl, EmailStr, Field


class CertificateUploadResponse(BaseModel):
    certificate_id: UUID
    qr_code_url: str
    verification_url: str
    expires_at: datetime
    holder_name: str
    certificate_title: str


class CertificateDetailResponse(BaseModel):
    id: UUID
    holder_email: EmailStr
    holder_name: str
    certificate_title: str
    created_at: datetime
    expires_at: datetime
    revoked_at: Optional[datetime] = None
    revocation_reason: Optional[str] = None
    file_size_bytes: int
    verification_count: int
    is_valid: bool

class VerificationResponse(BaseModel):
        session_token:str
        expires_in_seconds: int
        file_endpoint:str
        certificate_title:str
        holder_name:str
        expires_at: datetime
        is_valid: bool


class CertificateListResponse(BaseModel):
    id: UUID
    certificate_title: str
    holder_name: str
    created_at: datetime
    expires_at: datetime
    is_valid: bool


class RevocationResponse(BaseModel):
    certificate_id: UUID
    revoked_at: datetime
    reason: str
    success: bool


class QRRotationResponse(BaseModel):
    certificate_id: UUID
    new_qr_code_url: str
    old_qr_invalidated: bool
    regenerated_at: datetime


class HolderCertificateResponse(BaseModel):
    id: UUID
    certificate_title: str
    issuer_id: UUID
    issued_at: datetime
    expires_at: datetime
    is_valid: bool
    qr_code_available: bool


class CertificateStatusResponse(BaseModel):
    certificate_id: UUID
    is_valid: bool
    issued_at: datetime = Field(default_factory=datetime.now)
    expires_at: datetime = Field(default_factory=datetime.now)
    time_remaining_seconds: Optional[int] = None
    revoked_at: Optional[datetime] = None
    revocation_reason: Optional[str] = None


class VerificationSessionResponse(BaseModel):
    session_token: str
    expires_in_seconds: int
    file_endpoint: str
    certificate_title: str
    holder_name: str
    expires_at: datetime
    is_valid: bool


class AuditLogResponse(BaseModel):
    id: int
    action: str
    actor_role: str
    actor_id: Optional[str]
    ip_address: Optional[str]
    success: bool
    details: Optional[dict]
    timestamp: datetime


class VerificationStatsResponse(BaseModel):
    certificate_id: str
    period_days: int
    total_verifications: int
    unique_verifiers: int
    failed_attempts: int
    success_rate: float


class HealthResponse(BaseModel):
    status: str
    service: str
    version: str = "1.0.0"
    timestamp: datetime = Field(default_factory=datetime.now)



class ErrorResponse(BaseModel):
    detail: str
    error_code: Optional[str] = None
    request_id: Optional[str] = None
    timestamp: datetime = Field(default_factory=datetime.now)



class AdminCreateResponse(BaseModel):
    message: str
    admin_id: UUID
    email: str
    api_key: str  # Only returned once!
    warning: Optional[str] = None

# src/api/dependencies/types.py

from typing import Annotated

from fastapi import Depends
from sqlalchemy.ext.asyncio import AsyncSession

from src.api.dependencies.database import get_admin_service, get_audit_service, get_db
from src.services.admin_service import AdminService
from src.services.qr_generator import QRGeneratorService, get_qr_generator
from src.services.revocation_service import RevocationService, get_revocation_service
from src.services.audit_service import AuditService
from src.core.storage.s3_client import S3Client, get_s3_client
from src.core.crypto.hash_validator import HashValidator, get_hash_validator


# Database
DBSession = Annotated[AsyncSession, Depends(get_db)]

# Services
AdminServiceDep = Annotated[AdminService, Depends(get_admin_service)]
QRServiceDep = Annotated[QRGeneratorService, Depends(get_qr_generator)]
RevocationServiceDep = Annotated[RevocationService, Depends(get_revocation_service)]
AuditServiceDep = Annotated[AuditService, Depends(get_audit_service)]
S3ClientDep = Annotated[S3Client, Depends(get_s3_client)]
HashValidatorDep = Annotated[HashValidator, Depends(get_hash_validator)]

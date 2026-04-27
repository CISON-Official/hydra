# src/services/certificate_update_service.py

from typing import Optional, Dict, Any, List, Tuple
from uuid import UUID
from datetime import datetime, timezone
import hashlib
import uuid

from sqlalchemy.ext.asyncio import AsyncSession
from sqlalchemy import select, update, and_
from fastapi import HTTPException, status, UploadFile

from src.models.database.certificate import Certificate, CertificateVersion
from src.core.storage.s3_client import S3Client
from src.core.cache.redis_manager import RedisManager
from src.services.audit_service import AuditService
from src.services.qr_generator import QRGeneratorService
from src.core.crypto.hash_validator import HashValidator


class CertificateUpdateService:
    def __init__(
        self,
        s3_client: S3Client,
        redis_manager: RedisManager,
        audit_service: AuditService,
        qr_service: QRGeneratorService,
        hash_validator: HashValidator
    ):
        self.s3_client = s3_client
        self.redis = redis_manager
        self.audit = audit_service
        self.qr_service = qr_service
        self.hash_validator = hash_validator
    
    async def update_certificate_file(
        self,
        certificate_id: UUID,
        new_file: UploadFile,
        update_reason: str,
        updated_by: UUID,
        db: AsyncSession,
        preserve_qr: bool = True,
        notify_holder: bool = True
    ) -> Dict[str, Any]:
        """
        Update certificate file while maintaining audit trail
        
        Args:
            certificate_id: ID of certificate to update
            new_file: New PDF file
            update_reason: Reason for update
            updated_by: User ID performing update
            db: Database session
            preserve_qr: Keep existing QR code or generate new one
            notify_holder: Send notification to holder
        
        Returns:
            Dictionary with update details
        """
        
        # Get existing certificate
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()
        
        if not cert:
            raise HTTPException(status_code=404, detail="Certificate not found")
        
        # Check if certificate is revoked
        if cert.revoked_at:
            raise HTTPException(
                status_code=400, 
                detail="Cannot update revoked certificate. Unrevoke first."
            )
        
        # Check if expired
        if cert.expires_at < datetime.now(timezone.utc):
            raise HTTPException(
                status_code=400,
                detail="Cannot update expired certificate. Extend expiry first."
            )
        
        # Validate new file
        file_content = await new_file.read()
        
        if len(file_content) > 10 * 1024 * 1024:  # 10MB
            raise HTTPException(status_code=400, detail="File too large (max 10MB)")
        
        if new_file.content_type != "application/pdf":
            raise HTTPException(status_code=400, detail="Only PDF files allowed")
        
        # Calculate new file hash
        new_file_hash = hashlib.sha256(file_content).hexdigest()
        
        # Check if content actually changed
        if new_file_hash == cert.file_hash:
            raise HTTPException(
                status_code=400,
                detail="New file is identical to current version. No update needed."
            )
        
        # Save current version to history before updating
        await self._archive_current_version(cert, updated_by, update_reason, db)
        
        # Generate new S3 key for new version
        new_s3_key = f"certificates/{certificate_id}/v{cert.current_version + 1}.pdf"
        
        # Upload new version
        try:
            etag, file_size = await self.s3_client.upload_file(
                file_content,
                new_s3_key,
                new_file.content_type
            )
        except Exception as e:
            raise HTTPException(
                status_code=500,
                detail=f"Failed to upload new version: {str(e)}"
            )
        
        # Store old key for potential rollback
        old_s3_key = cert.s3_key
        old_version = cert.current_version
        
        # Update certificate record
        cert.current_version += 1
        cert.file_hash = new_file_hash
        cert.s3_key = new_s3_key
        cert.file_size_bytes = file_size
        cert.updated_at = datetime.now(timezone.utc)
        cert.last_updated_by = updated_by
        cert.update_reason = update_reason
        
        # Optionally regenerate QR code (invalidates old links)
        new_qr_url = None
        if not preserve_qr:
            new_qr_url = await self.qr_service.regenerate_qr(certificate_id, db)
        
        try:
            await db.commit()
            
            # Invalidate cache
            await self.redis.invalidate_certificate(str(certificate_id))
            
            # Log update
            await self.audit.log_action(
                certificate_id=certificate_id,
                action="update_certificate",
                actor_role="issuer",
                actor_id=str(updated_by),
                details={
                    "old_version": old_version,
                    "new_version": cert.current_version,
                    "old_hash": cert.file_hash[:8],
                    "new_hash": new_file_hash[:8],
                    "update_reason": update_reason,
                    "qr_regenerated": not preserve_qr,
                    "old_s3_key": old_s3_key,
                    "new_s3_key": new_s3_key
                }
            )
            
            # Notify holder (async)
            if notify_holder:
                await self._notify_holder_of_update(cert, update_reason)
            
            return {
                "certificate_id": certificate_id,
                "updated": True,
                "old_version": old_version,
                "new_version": cert.current_version,
                "update_reason": update_reason,
                "updated_at": cert.updated_at,
                "qr_code_updated": not preserve_qr,
                "new_qr_url": new_qr_url if new_qr_url else None
            }
            
        except Exception as e:
            await db.rollback()
            raise HTTPException(
                status_code=500,
                detail=f"Failed to update certificate: {str(e)}"
            )
    
    async def _archive_current_version(
        self,
        cert: Certificate,
        updated_by: UUID,
        update_reason: str,
        db: AsyncSession
    ):
        """Archive current version before updating"""
        version_archive = CertificateVersion(
            certificate_id=cert.id,
            version_number=cert.current_version,
            file_hash=cert.file_hash,
            s3_key=cert.s3_key,
            file_size_bytes=cert.file_size_bytes,
            updated_by=updated_by,
            update_reason=f"Archived before update: {update_reason}"
        )
        db.add(version_archive)
    
    async def rollback_to_version(
        self,
        certificate_id: UUID,
        target_version: int,
        rollback_reason: str,
        rolled_back_by: UUID,
        db: AsyncSession
    ) -> Dict[str, Any]:
        """
        Rollback certificate to a previous version
        """
        
        # Get current certificate
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()
        
        if not cert:
            raise HTTPException(status_code=404, detail="Certificate not found")
        
        # Get target version
        stmt = select(CertificateVersion).where(
            and_(
                CertificateVersion.certificate_id == certificate_id,
                CertificateVersion.version_number == target_version
            )
        )
        result = await db.execute(stmt)
        target_version_data = result.scalar_one_or_none()
        
        if not target_version_data:
            raise HTTPException(
                status_code=404,
                detail=f"Version {target_version} not found"
            )
        
        # Archive current version before rollback
        await self._archive_current_version(cert, rolled_back_by, f"Pre-rollback to v{target_version}", db)
        
        # Rollback to target version
        old_current_version = cert.current_version
        
        cert.current_version += 1
        cert.file_hash = target_version_data.file_hash
        cert.s3_key = target_version_data.s3_key
        cert.file_size_bytes = target_version_data.file_size_bytes
        cert.updated_at = datetime.now(timezone.utc)
        cert.last_updated_by = rolled_back_by
        cert.update_reason = f"Rollback to version {target_version}: {rollback_reason}"
        
        await db.commit()
        
        # Invalidate cache
        await self.redis.invalidate_certificate(str(certificate_id))
        
        # Log rollback
        await self.audit.log_action(
            certificate_id=certificate_id,
            action="rollback_certificate",
            actor_role="issuer",
            actor_id=str(rolled_back_by),
            details={
                "from_version": old_current_version,
                "to_version": target_version,
                "reason": rollback_reason
            }
        )
        
        return {
            "certificate_id": certificate_id,
            "rolled_back": True,
            "previous_version": old_current_version,
            "current_version": cert.current_version,
            "target_version_restored": target_version,
            "rollback_reason": rollback_reason
        }
    
    async def get_version_history(
        self,
        certificate_id: UUID,
        db: AsyncSession,
        limit: int = 50
    ) -> List[Dict[str, Any]]:
        """Get version history for a certificate"""
        
        stmt = select(CertificateVersion).where(
            CertificateVersion.certificate_id == certificate_id
        ).order_by(
            CertificateVersion.version_number.desc()
        ).limit(limit)
        
        result = await db.execute(stmt)
        versions = result.scalars().all()
        
        # Get current version info
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        current = result.scalar_one_or_none()
        
        history = []
        
        for version in versions:
            history.append({
                "version": version.version_number,
                "is_current": False,
                "file_hash": version.file_hash[:16] + "...",
                "file_size_bytes": version.file_size_bytes,
                "updated_by": str(version.updated_by),
                "update_reason": version.update_reason,
                "updated_at": version.created_at.isoformat()
            })
        
        # Add current version
        if current:
            history.insert(0, {
                "version": current.current_version,
                "is_current": True,
                "file_hash": current.file_hash[:16] + "...",
                "file_size_bytes": current.file_size_bytes,
                "updated_by": str(current.last_updated_by) if current.last_updated_by else None,
                "update_reason": current.update_reason,
                "updated_at": current.updated_at.isoformat()
            })
        
        return history
    
    async def compare_versions(
        self,
        certificate_id: UUID,
        version_a: int,
        version_b: int,
        db: AsyncSession
    ) -> Dict[str, Any]:
        """Compare two versions of a certificate"""
        
        # Get version A
        stmt = select(CertificateVersion).where(
            and_(
                CertificateVersion.certificate_id == certificate_id,
                CertificateVersion.version_number == version_a
            )
        )
        result = await db.execute(stmt)
        version_a_data = result.scalar_one_or_none()
        
        if not version_a_data and version_a != 0:
            # Try current version?
            if version_a == 0:
                stmt = select(Certificate).where(Certificate.id == certificate_id)
                result = await db.execute(stmt)
                version_a_data = result.scalar_one()
        
        # Get version B
        stmt = select(CertificateVersion).where(
            and_(
                CertificateVersion.certificate_id == certificate_id,
                CertificateVersion.version_number == version_b
            )
        )
        result = await db.execute(stmt)
        version_b_data = result.scalar_one_or_none()
        
        if not version_b_data and version_b == 0:
            stmt = select(Certificate).where(Certificate.id == certificate_id)
            result = await db.execute(stmt)
            version_b_data = result.scalar_one()
        
        if not version_a_data or not version_b_data:
            raise HTTPException(status_code=404, detail="Version not found")
        
        return {
            "certificate_id": certificate_id,
            "version_a": {
                "number": version_a,
                "file_hash": version_a_data.file_hash,
                "file_size": version_a_data.file_size_bytes,
                "updated_at": version_a_data.created_at.isoformat() if hasattr(version_a_data, 'created_at') else version_a_data.updated_at.isoformat()
            },
            "version_b": {
                "number": version_b,
                "file_hash": version_b_data.file_hash,
                "file_size": version_b_data.file_size_bytes,
                "updated_at": version_b_data.created_at.isoformat() if hasattr(version_b_data, 'created_at') else version_b_data.updated_at.isoformat()
            },
            "identical": version_a_data.file_hash == version_b_data.file_hash,
            "size_difference_bytes": abs(version_a_data.file_size_bytes - version_b_data.file_size_bytes)
        }
    
    async def extend_expiry_with_update(
        self,
        certificate_id: UUID,
        new_expiry_date: datetime,
        extension_reason: str,
        updated_by: UUID,
        db: AsyncSession,
        update_file: Optional[UploadFile] = None,
    ) -> Dict[str, Any]:
        """
        Extend certificate expiry and optionally update file
        """
        
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()
        
        if not cert:
            raise HTTPException(status_code=404, detail="Certificate not found")
        
        old_expiry = cert.expires_at
        cert.expires_at = new_expiry_date
        cert.updated_at = datetime.now(timezone.utc)
        cert.last_updated_by = updated_by
        
        if update_file:
            # Update file as well
            file_update_result = await self.update_certificate_file(
                certificate_id=certificate_id,
                new_file=update_file,
                update_reason=f"Extension and file update: {extension_reason}",
                updated_by=updated_by,
                db=db,
                preserve_qr=True,
                notify_holder=True
            )
        else:
            await db.commit()
            await self.redis.invalidate_certificate(str(certificate_id))
            
            await self.audit.log_action(
                certificate_id=certificate_id,
                action="extend_expiry",
                actor_role="issuer",
                actor_id=str(updated_by),
                details={
                    "old_expiry": old_expiry.isoformat(),
                    "new_expiry": new_expiry_date.isoformat(),
                    "reason": extension_reason
                }
            )
            
            file_update_result = {"updated": False}
        
        return {
            "certificate_id": certificate_id,
            "old_expiry": old_expiry.isoformat(),
            "new_expiry": new_expiry_date.isoformat(),
            "file_updated": file_update_result.get("updated", False),
            "new_version": cert.current_version if file_update_result.get("updated") else None,
            "extension_reason": extension_reason
        }
    
    async def _notify_holder_of_update(self, cert: Certificate, update_reason: str):
        """Send notification to certificate holder about update"""
        # Implement email/webhook notification
        # This should be async and non-blocking
        pass
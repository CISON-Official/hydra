from uuid import UUID
from datetime import datetime, timezone

from fastapi.responses import JSONResponse, RedirectResponse, StreamingResponse
from fastapi.templating import Jinja2Templates
from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from fastapi import APIRouter, Depends, HTTPException, Query, Request, Path, Response

# from src.main import get_verification_service
from src.core.crypto.hmac_handler import HMACHandler, get_hmac_handler
from src.core.cache.redis_manager import RedisManager, get_redis
from src.core.crypto.token_manager import TokenManager, get_token_manager
from src.core.storage.s3_client import S3Client, get_s3_client
from src.models.database.certificate import Certificate
from src.services.audit_service import AuditService
from src.api.dependencies.database import get_audit_service, get_db

router = APIRouter()

templates = Jinja2Templates(directory="templates")


@router.get("/verify/{certificate_id}")
async def verify_and_redirect(
    request: Request,
    certificate_id: UUID = Path(..., description="Certificate UUID"),
    hmac: str = Query(..., description="HMAC signature from QR code"),
    format: str = Query("html", description="Response format: html, json, or direct"),
    db: AsyncSession = Depends(get_db),
    redis_manager: RedisManager = Depends(get_redis),
    hmac_handler: HMACHandler = Depends(get_hmac_handler),
    token_manager: TokenManager = Depends(get_token_manager),
    audit_service: AuditService = Depends(get_audit_service),
):
    """
    QR Code verification endpoint.
    Users scan QR code -> this endpoint -> validates -> returns viewer or file.
    """

    client_ip = request.client.host if request.client else "unknown"
    user_agent = request.headers.get("user-agent", "unknown")

    # Step 1: Get certificate with cache
    cached_status = await redis_manager.get_certificate_status(str(certificate_id))

    stmt = select(Certificate).where(Certificate.id == certificate_id)
    result = await db.execute(stmt)
    cert = result.scalar_one_or_none()

    if not cert:
        await audit_service.log_action(
            certificate_id=certificate_id,
            action="verify",
            actor_role="verifier",
            ip_address=client_ip,
            user_agent=user_agent,
            success=False,
            details={"error": "Certificate not found"},
        )
        raise HTTPException(status_code=404, detail="Certificate not found")

    # Check validity
    now = datetime.now(timezone.utc)
    if cert.revoked_at:
        print("Certificate is revoked")
    print(f"Certificate expired at {cert.expires_at}")
    is_valid = not cert.revoked_at and cert.expires_at > now
    expires_at = cert.expires_at

    # Cache for 5 minutes
    cert_data = {
        "id": str(cert.id),
        "title": cert.certificate_title,
        "holder_name": cert.holder_name,
        "holder_email": cert.holder_email,
        "expires_at": cert.expires_at.isoformat(),
        "revoked_at": cert.revoked_at.isoformat() if cert.revoked_at else None,
        "current_version": cert.current_version,
        "qr_nonce": cert.qr_nonce,
    }

    # await redis_manager.cache_certificate_status(
    #     str(certificate_id), is_valid, expires_at, data=cert_data
    # )

    # Step 2: Verify HMAC signature
    if not cert_data and "cert" not in locals():
        # Need to fetch cert_data from database if not in cache
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one()
        cert_data = {"id": str(cert.id), "qr_nonce": cert.qr_nonce}

    nonce = cert_data.get("qr_nonce")  # type: ignore
    if not nonce:
        raise HTTPException(
            status_code=400, detail="QR code not configured for this certificate"
        )

    is_valid_hmac = hmac_handler.verify_signature(certificate_id, nonce, hmac)

    if not is_valid_hmac:
        await audit_service.log_action(
            certificate_id=certificate_id,
            action="verify",
            actor_role="verifier",
            ip_address=client_ip,
            user_agent=user_agent,
            success=False,
            details={"error": "Invalid HMAC signature"},
        )
        raise HTTPException(status_code=401, detail="Invalid QR code signature")

    # Step 3: Check certificate validity
    if not is_valid:
        await audit_service.log_action(
            certificate_id=certificate_id,
            action="verify",
            actor_role="verifier",
            ip_address=client_ip,
            user_agent=user_agent,
            success=False,
            details={"error": "Certificate expired or revoked"},
        )

        if format == "json":
            return JSONResponse(
                status_code=410,
                content={
                    "error": "Certificate expired or revoked",
                    "certificate_id": str(certificate_id),
                    "expires_at": expires_at.isoformat(),
                    "is_valid": False,
                },
            )
        else:
            return templates.TemplateResponse(
                request,
                "expired.html",
                {
                    "request": request,
                    "certificate_id": certificate_id,
                    "expires_at": expires_at,
                    "certificate_title": cert_data.get("title", "Certificate"),  # type: ignore
                },
            )

    # Step 4: Generate session token for file access
    session_token, ttl_seconds = token_manager.create_session_token(
        certificate_id, expires_at
    )

    # Step 5: Log successful verification
    await audit_service.log_action(
        certificate_id=certificate_id,
        action="verify",
        actor_role="verifier",
        actor_id=client_ip,
        ip_address=client_ip,
        user_agent=user_agent,
        success=True,
        details={"method": "qr_code", "format": format},
    )

    # Step 6: Return based on format
    if format == "json":
        return JSONResponse(
            {
                "success": True,
                "certificate_id": str(certificate_id),
                "certificate_title": cert_data.get("title"),  # type: ignore
                "holder_name": cert_data.get("holder_name"),  # type: ignore
                "expires_at": expires_at.isoformat(),
                "is_valid": True,
                "session_token": session_token,
                "download_url": f"/api/certificates/{certificate_id}/download",
                "stream_url": f"/api/certificates/{certificate_id}/stream",
                "token_expires_in": ttl_seconds,
            }
        )

    elif format == "direct":
        # Redirect to download with session token
        return RedirectResponse(
            url=f"/api/certificates/{certificate_id}/download?token={session_token}",
            status_code=302,
        )

    else:  # html (default)
        return templates.TemplateResponse(
            request,
            "viewer.html",
            {
                "request": request,
                "certificate_id": certificate_id,
                "certificate_title": cert_data.get("title"),  # type: ignore
                "holder_name": cert_data.get("holder_name"),  # type: ignore
                "issued_to": cert_data.get("holder_email"),  # type: ignore
                "expires_at": expires_at,
                "session_token": session_token,
                "token_expires_in": ttl_seconds,
                "verification_time": datetime.now(timezone.utc).isoformat(),
            },
        )


@router.get("/certificates/{certificate_id}/info")
async def get_certificate_info(
    certificate_id: UUID,
    hmac: str = Query(..., description="HMAC signature"),
    db: AsyncSession = Depends(get_db),
    hmac_handler: HMACHandler = Depends(get_hmac_handler),
):
    """
    Get certificate metadata without downloading (for preview)
    """

    # Get certificate
    stmt = select(Certificate).where(Certificate.id == certificate_id)
    result = await db.execute(stmt)
    cert = result.scalar_one_or_none()

    if not cert:
        raise HTTPException(status_code=404, detail="Certificate not found")

    # Verify HMAC
    if not hmac_handler.verify_signature(certificate_id, cert.qr_nonce, hmac):  # type: ignore
        raise HTTPException(status_code=401, detail="Invalid HMAC signature")

    # Check validity
    now = datetime.now(timezone.utc)
    is_valid = not cert.revoked_at and cert.expires_at > now

    return {
        "certificate_id": str(cert.id),
        "title": cert.certificate_title,
        "holder_name": cert.holder_name,
        "holder_email": cert.holder_email,
        "issued_at": cert.created_at.isoformat(),
        "expires_at": cert.expires_at.isoformat(),
        "is_valid": is_valid,
        "current_version": cert.current_version,
        "file_size_bytes": cert.file_size_bytes,
        "revoked": cert.revoked_at is not None,
    }


@router.get("/certificates/{certificate_id}/stream")
async def stream_certificate(
    certificate_id: UUID,
    token: str = Query(..., description="Session token"),
    db: AsyncSession = Depends(get_db),
    s3_client: S3Client = Depends(get_s3_client),
    token_manager: TokenManager = Depends(get_token_manager),
    redis_manager: RedisManager = Depends(get_redis),
    audit_service: AuditService = Depends(get_audit_service),
):
    """
    Stream certificate file in chunks (for large files or better UX)
    """

    # Validate token (same as download endpoint)
    cert_id, token_expiry, is_valid = token_manager.validate_session_token(token)

    if not is_valid or cert_id != certificate_id:
        raise HTTPException(status_code=401, detail="Invalid or expired session token")

    # Validate certificate status
    cached_status = await redis_manager.get_certificate_status(str(certificate_id))
    if cached_status and not cached_status["is_valid"]:
        raise HTTPException(status_code=410, detail="Certificate expired or revoked")

    # Get S3 key
    stmt = select(Certificate).where(Certificate.id == certificate_id)
    result = await db.execute(stmt)
    cert = result.scalar_one()

    # Stream from S3
    async def file_stream():
        async for chunk in s3_client.get_file_stream(cert.s3_key): 
            yield chunk

    await audit_service.log_action(
        certificate_id=certificate_id,
        action="stream",
        actor_role="verifier",
        details={"streaming": True},
    )

    return StreamingResponse(
        file_stream(),
        media_type="application/pdf",
        headers={
            "Content-Disposition": "inline",
            "Cache-Control": "no-cache",
            "X-Certificate-ID": str(certificate_id),
        },
    )

@router.get("/certificates/{certificate_id}/download")
async def download_certificate(
    certificate_id: UUID,
    token: str = Query(..., description="Session token from verification"),
    inline: bool = Query(True, description="Display inline or download"),
    db: AsyncSession = Depends(get_db),
    s3_client: S3Client = Depends(get_s3_client),
    token_manager: TokenManager = Depends(get_token_manager),
    redis_manager: RedisManager = Depends(get_redis),
    audit_service: AuditService = Depends(get_audit_service)
):
    """
    Download certificate file using session token.
    This endpoint is called after QR verification.
    """
    
    # Validate session token
    cert_id, token_expiry, is_valid = token_manager.validate_session_token(token)

    print(token, cert_id, token_expiry, is_valid)
    
    if not is_valid or cert_id != certificate_id:
        raise HTTPException(
            status_code=401,
            detail="Invalid or expired session token. Please scan QR code again."
        )
    
    # Re-validate certificate status at download time (critical!)
    cached_status = await redis_manager.get_certificate_status(str(certificate_id))
    
    if cached_status:
        is_cert_valid = cached_status["is_valid"]
        expires_at = datetime.fromisoformat(cached_status["expires_at"])
    else:
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one_or_none()
        
        if not cert:
            raise HTTPException(status_code=404, detail="Certificate not found")
        
        now = datetime.now(timezone.utc)
        is_cert_valid = not cert.revoked_at and cert.expires_at > now
        expires_at = cert.expires_at
    
    if not is_cert_valid:
        raise HTTPException(
            status_code=410,
            detail="Certificate expired or revoked. Cannot download."
        )
    
    # Get the file from S3
    try:
        # Get current version key
        stmt = select(Certificate).where(Certificate.id == certificate_id)
        result = await db.execute(stmt)
        cert = result.scalar_one()
        s3_key = cert.s3_key
        
        # Get file from S3
        file_content = await s3_client.get_file(s3_key)
        
        # Log download
        await audit_service.log_action(
            certificate_id=certificate_id,
            action="download",
            actor_role="verifier",
            details={
                "via": "qr_code",
                "token_valid": True,
                "file_size": len(file_content)
            }
        )
        
        # Determine content disposition
        disposition = "inline" if inline else "attachment"
        filename = f"certificate_{certificate_id}.pdf"
        
        return Response(
            content=file_content,
            media_type="application/pdf",
            headers={
                "Content-Disposition": f"{disposition}; filename={filename}",
                "Content-Type": "application/pdf",
                "Cache-Control": "no-store, no-cache, must-revalidate, private",
                "X-Certificate-ID": str(certificate_id),
                "X-Valid-Until": expires_at.isoformat(),
                "X-Session-Expires": token_expiry.isoformat() if token_expiry else "N/A"
            }
        )
        
    except Exception as e:
        await audit_service.log_action(
            certificate_id=certificate_id,
            action="download",
            actor_role="verifier",
            success=False,
            details={"error": str(e)}
        )
        raise HTTPException(status_code=500, detail=f"Failed to retrieve certificate: {str(e)}")

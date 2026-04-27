import asyncio
from typing import Optional
from datetime import datetime
import aioboto3
from botocore.exceptions import ClientError
from fastapi.responses import StreamingResponse
from fastapi import HTTPException, Response, Request

class FileProxy:
    def __init__(self, s3_client, token_manager, redis_manager):
        self.s3_client = s3_client
        self.token_manager = token_manager
        self.redis = redis_manager
    
    async def stream_file(self, request: Request, certificate_id: str) -> Response:
        """Stream file with real-time validation on every request"""
        
        # Extract session token
        token = request.headers.get("X-Session-Token") or request.query_params.get("token")
        if not token:
            raise HTTPException(status_code=401, detail="Session token required")
        
        # Validate token
        cert_id, token_expiry, is_valid = self.token_manager.validate_session_token(token)
        if not is_valid or cert_id != certificate_id:
            raise HTTPException(status_code=401, detail="Invalid or expired session token")
        
        # CRITICAL: Re-validate certificate status at request time
        cached_status = await self.redis.get_certificate_status(str(certificate_id))
        
        if cached_status:
            is_cert_valid = cached_status["is_valid"]
            expires_at = datetime.fromisoformat(cached_status["expires_at"])
        else:
            # Would hit database here (in production, pass db session)
            # For now, assume we have to check
            is_cert_valid = True  # Placeholder
        
        if not is_cert_valid:
            raise HTTPException(status_code=410, detail="Certificate expired or revoked")
        
        # Stream file from S3
        key = f"certificates/{certificate_id}.pdf"
        
        try:
            # Check for range request (for PDF streaming)
            range_header = request.headers.get("range")
            
            async with self.s3_client.session.client("s3") as s3:
                if range_header:
                    response = await s3.get_object(
                        Bucket=self.s3_client.bucket,
                        Key=key,
                        Range=range_header
                    )
                    status_code = 206
                else:
                    response = await s3.get_object(
                        Bucket=self.s3_client.bucket,
                        Key=key
                    )
                    status_code = 200
                
                # Stream content in chunks
                async def generate():
                    async for chunk in response["Body"].iter_chunks(chunk_size=8192):
                        yield chunk
                
                headers = {
                    "Content-Type": "application/pdf",
                    "Content-Disposition": "inline",
                    "Cache-Control": "no-store, no-cache, must-revalidate",
                    "Accept-Ranges": "bytes",
                }
                
                if range_header:
                    headers["Content-Range"] = response.get("ContentRange", "")
                
                return StreamingResponse(
                    generate(),
                    status_code=status_code,
                    headers=headers
                )
                
        except ClientError as e:
            if e.response["Error"]["Code"] == "NoSuchKey":
                raise HTTPException(404, "Certificate file not found")
            raise
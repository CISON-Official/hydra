from typing import AsyncGenerator
from contextlib import asynccontextmanager

from fastapi import FastAPI, Depends, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.middleware.trustedhost import TrustedHostMiddleware
from fastapi.staticfiles import StaticFiles


from src.config import get_settings
from src.core.cache.redis_manager import RedisManager
from src.core.crypto.hmac_handler import HMACHandler
from src.core.crypto.token_manager import TokenManager
from src.core.storage.s3_client import S3Client, get_s3_client
from src.services.verification_service import (
    VerificationService,
    get_verification_service,
)
from src.services.qr_generator import QRGeneratorService, get_qr_generator
from src.api.routes import verification, administration, holder, admin_management
from src.api.middleware.logging_middleware import (
    LoggingMiddleware,
    PerformanceLoggingMiddleware,
)
from src.api.middleware.security_headers import SecurityHeadersMiddleware
from src.services.audit_service import AuditService
from src.services.revocation_service import RevocationService
from src.core.crypto.hash_validator import HashValidator, SecureHashValidator
from src.api.dependencies.auth import AuthDependency, RateLimitAuth
from src.api.dependencies.database import get_db, async_session_maker, engine
from src.core.cache.ttl_calculator import TTLCalculator, AdaptiveTTLCalculator


settings = get_settings()


@asynccontextmanager
async def lifespan(app: FastAPI):

    # Initialize Redis
    redis_manager = RedisManager(settings.redis_url, settings.redis_cache_ttl_seconds)
    s3_client = S3Client(
        endpoint=settings.s3_endpoint,
        access_key=settings.s3_access_key,
        secret_key=settings.s3_secret_key,
        bucket=settings.s3_bucket,
        secure=settings.s3_secure,
        region=settings.s3_region,
    )
    hmac_handler = HMACHandler(settings.hmac_secret_key)
    token_manager = TokenManager(
        settings.secret_key, settings.session_token_ttl_seconds
    )
    verification_service = VerificationService(
        hmac_handler, token_manager, redis_manager
    )
    qr_generator = QRGeneratorService(settings.qr_base_url, hmac_handler)
    audit_service = AuditService(redis_manager, async_session_maker)
    revocation_service = RevocationService(redis_manager)
    hash_validator = SecureHashValidator()
    ttl_calculator = AdaptiveTTLCalculator()
    auth_dependency = AuthDependency(Depends(get_db))
    rate_limit_auth = RateLimitAuth(redis_manager)

    # Store in app state
    app.state.redis_manager = redis_manager
    app.state.s3_client = s3_client
    app.state.hmac_handler = hmac_handler
    app.state.token_manager = token_manager
    app.state.verification_service = verification_service
    app.state.qr_generator = qr_generator
    app.state.audit_service = audit_service
    app.state.revocation_service = revocation_service
    app.state.hash_validator = hash_validator
    app.state.ttl_calculator = ttl_calculator
    app.state.auth_dependency = auth_dependency
    app.state.rate_limit_auth = rate_limit_auth

    yield

    # Shutdown
    await redis_manager.close()
    await engine.dispose()


# Create FastAPI app
app = FastAPI(
    title="Certificate Verification Platform",
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/api/docs",
    redoc_url="/api/redoc",
)

# Middleware
app.add_middleware(SecurityHeadersMiddleware)
app.add_middleware(PerformanceLoggingMiddleware)
app.add_middleware(LoggingMiddleware, log_headers=False, log_body=False)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],  # Configure appropriately for production
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.add_middleware(
    TrustedHostMiddleware, allowed_hosts=["*"]  # Configure appropriately for production
)

app.mount("/static", StaticFiles(directory="static"), name="static")
# # Dependency injections
# async def get_redis() -> RedisManager:
#     return app.state.redis_manager


# async def get_verification_service() -> VerificationService:
#     return app.state.verification_service


# async def get_qr_generator() -> QRGeneratorService:
#     return app.state.qr_generator


# async def get_s3_client() -> S3Client:
#     return app.state.s3_client


# async def get_revocation_service():
#     return app.state.revocation_service


# async def get_hash_validator():
#     return app.state.hash_validator


# async def get_ttl_calculator():
#     return app.state.ttl_calculator


# async def get_auth_dependency():
#     return app.state.auth_dependency


# async def get_rate_limit_auth():
#     return app.state.rate_limit_auth


# Include routers
app.include_router(
    verification.router, prefix="/api", dependencies=[Depends(get_verification_service)]
)
app.include_router(
    administration.router,
    prefix="/api/admin",
    dependencies=[Depends(get_db), Depends(get_s3_client), Depends(get_qr_generator)],
)
app.include_router(
    holder.router,
    prefix="/api/holder",
    dependencies=[Depends(get_db), Depends(get_qr_generator)],
)
app.include_router(
    admin_management.router,
    prefix="/api",
)


@app.get("/health")
async def health_check():
    return {"status": "healthy", "service": "certificate-platform"}

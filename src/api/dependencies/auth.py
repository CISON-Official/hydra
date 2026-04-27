import hmac
import secrets
import hashlib
from functools import lru_cache
from datetime import datetime, timezone
from typing import Optional, List, Dict, Any

from sqlalchemy import select
from sqlalchemy.ext.asyncio import AsyncSession
from fastapi import Depends, HTTPException, status, Request
from fastapi.security import APIKeyHeader, HTTPBearer, HTTPAuthorizationCredentials

from src.services.admin_service import AdminService
from src.config import get_settings
from src.models.database.user import User
from src.api.dependencies.database import get_admin_service, get_db
from src.services.audit_service import AuditService
from src.core.cache.redis_manager import RedisManager
from src.api.dependencies.database import get_audit_service

settings = get_settings()

# API Key authentication
api_key_header = APIKeyHeader(name="X-API-Key", auto_error=False)
bearer_scheme = HTTPBearer(auto_error=False)


class AuthDependency:
    """Main authentication handler"""

    def __init__(self, db: AsyncSession):
        self.session = db
        self.jwt_secret = settings.secret_key

    async def authenticate_api_key(self, api_key: str) -> Optional[Dict[str, Any]]:
        """Authenticate using API key"""
        hash = hashlib.sha256(api_key.encode()).hexdigest()
        query = select(User).where(User.api_key_hash == hash)
        result = await self.session.execute(query)
        user = result.scalar_one_or_none()

        if user:
            return {
                "id": user.id,
                "email": user.email,
                "role": user.role,
                "api_key": user.api_key_hash,
            }
        return None

    async def authenticate_bearer_token(self, token: str) -> Optional[Dict[str, Any]]:
        """Authenticate using Bearer token (JWT)"""
        try:
            hash = hashlib.sha256(token.encode()).hexdigest()
            query = select(User).where(User.api_key_hash == hash)
            result = await self.session.execute(query)
            user = result.scalar_one_or_none()

            if user:
                return {
                    "id": user.id,
                    "email": user.email,
                    "role": user.role,
                    "api_key": user.api_key_hash,
                }
            return None
        except Exception:
            pass
        return None

    async def authenticate_hmac_signature(self, request: Request, secret: str) -> bool:
        """Authenticate using HMAC signature in headers"""
        signature = request.headers.get("X-Signature")
        timestamp = request.headers.get("X-Timestamp")

        if not signature or not timestamp:
            return False

        # Check timestamp freshness (5 minutes)
        try:
            req_time = datetime.fromisoformat(timestamp)
            now = datetime.now(timezone.utc)
            if abs((now - req_time).total_seconds()) > 300:
                return False
        except ValueError:
            return False

        # Compute expected signature
        body = await request.body() if request.method in ["POST", "PUT"] else b""
        message = (
            f"{request.method}{request.url.path}{timestamp}{body.decode()}".encode()
        )
        expected = hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()

        return hmac.compare_digest(expected, signature)


# Dependency for API Key auth
async def get_current_user(
    api_key: Optional[str] = Depends(api_key_header),
    bearer: Optional[HTTPAuthorizationCredentials] = Depends(bearer_scheme),
    db: AsyncSession = Depends(get_db),
) -> Dict[str, Any]:
    """Get current authenticated user"""

    auth_service = AuthDependency(db)
    # Try API key first
    if api_key:
        user = await auth_service.authenticate_api_key(api_key)
        if user:
            return user

    # Try Bearer token
    if bearer:
        user = await auth_service.authenticate_bearer_token(bearer.credentials)
        if user:
            return user

    raise HTTPException(
        status_code=status.HTTP_401_UNAUTHORIZED,
        detail="Invalid authentication credentials",
        headers={"WWW-Authenticate": "APIKey"},
    )


def require_role(allowed_roles: List[str]):
    """Dependency factory for role-based access control"""

    async def role_checker(
        current_user: Dict[str, Any] = Depends(get_current_user),
    ) -> Dict[str, Any]:
        if current_user["role"] not in allowed_roles:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail=f"Role '{current_user['role']}' not allowed. Required: {allowed_roles}",
            )
        return current_user

    return role_checker


def require_holder_access(certificate_owner_email: str):
    """Check if holder has access to specific certificate"""

    async def checker(
        current_user: Dict[str, Any] = Depends(get_current_user),
        audit_service: AuditService = Depends(get_audit_service),
    ):
        if current_user["role"] == "admin":
            return current_user

        if current_user["role"] == "holder":
            # In production, verify holder email matches
            if current_user.get("email") == certificate_owner_email:
                return current_user

        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Access denied: You are not the certificate holder",
        )

    return checker


# Simple token verification for holder routes (production would use proper JWT)
async def verify_holder_token(email: str, token: str) -> bool:
    """Verify holder token (simplified - use JWT in production)"""
    # This is a placeholder - implement proper JWT validation
    expected = hashlib.sha256(f"{email}:{settings.secret_key}".encode()).hexdigest()
    return hmac.compare_digest(token, expected[:32])


class RateLimitAuth:
    """Authentication-aware rate limiting"""

    def __init__(self, redis_manager: RedisManager):
        self.redis = redis_manager

    async def check_rate_limit(
        self, request: Request, user: Optional[Dict[str, Any]] = None
    ) -> bool:
        """Apply different rate limits based on auth status"""

        if user:
            # Authenticated users: higher limit
            key = f"auth_user:{user['role']}:{user['id']}"
            limit = 100  # 100 requests per minute
        else:
            # Unauthenticated: lower limit
            key = f"unauth:{request.client.host}"  # type: ignore
            limit = 10

        return await self.redis.check_rate_limit(key, limit, 60)


# Helper function to generate API keys
def generate_api_key(prefix: str = "") -> str:
    """Generate secure API key"""
    random_part = secrets.token_urlsafe(32)
    return f"{prefix}_{random_part}" if prefix else random_part


# Add to src/api/dependencies/auth.py


async def require_admin_with_single_constraint(
    current_user: Dict[str, Any] = Depends(get_current_user),
    db: AsyncSession = Depends(get_db),
    admin_service: AdminService = Depends(get_admin_service),
):
    """Enhanced admin check that enforces single active admin"""

    if current_user["role"] != "admin":
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN, detail="Admin privileges required"
        )

    # Verify this is the ACTIVE admin (in case of multiple admins scenario)
    active_admin = await admin_service.get_active_admin(db)

    if not active_admin:
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="No active admin found. System in inconsistent state.",
        )

    if str(active_admin.id) != current_user.get("id"):
        raise HTTPException(
            status_code=status.HTTP_403_FORBIDDEN,
            detail="Only the active admin can perform this operation",
        )

    return current_user


# Admin initialization endpoint
async def initialize_first_admin(
    email: str,
    name: str,
    api_key: str,
    db: AsyncSession = Depends(get_db),
    admin_service: AdminService = Depends(get_admin_service),
):
    """Initialize the first admin user (only works if no admin exists)"""

    # Hash the API key (use bcrypt or similar)
    api_key_hash = admin_service._hash_api_key(api_key)  # Implement this

    admin = await admin_service.create_first_admin(db, email, name, api_key_hash)

    admin, api_key = admin

    return {
        "message": "First admin created successfully",
        "admin_id": str(admin.id),
        "email": admin.email,
    }

from fastapi import Request, Response
from starlette.middleware.base import BaseHTTPMiddleware
from typing import Callable


class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        response = await call_next(request)

        path = request.url.path

        # 🚨 Skip Swagger / ReDoc / OpenAPI
        if path.endswith(("/docs", "/redoc", "/openapi.json")):
            return response

        # --- Security headers only for API routes ---

        response.headers["Strict-Transport-Security"] = (
            "max-age=31536000; includeSubDomains; preload"
        )

        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-XSS-Protection"] = "1; mode=block"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"

        response.headers["Content-Security-Policy"] = "; ".join(
            [
                "default-src 'self'",
                "img-src 'self' data:",
                "connect-src 'self'",
                "script-src 'self' https://cdnjs.cloudflare.com",
                "style-src 'self' https://cdnjs.cloudflare.com",
            ]
        )
        response.headers["Cross-Origin-Resource-Policy"] = "cross-origin"

        response.headers["Cross-Origin-Embedder-Policy"] = "require-corp"

        if "server" in response.headers:
            del response.headers["server"]

        return response


class CORSMiddlewareConfig:
    """CORS configuration for security"""

    @staticmethod
    def get_cors_config(allowed_origins: list = []):
        if allowed_origins is None:
            # In production, restrict this!
            allowed_origins = [
                "https://verify.yourdomain.com",
                "https://admin.yourdomain.com",
            ]

        return {
            "allow_origins": allowed_origins,
            "allow_credentials": True,
            "allow_methods": ["GET", "POST", "PUT", "DELETE", "OPTIONS"],
            "allow_headers": [
                "Content-Type",
                "Authorization",
                "X-API-Key",
                "X-Session-Token",
                "X-Request-ID",
            ],
            "expose_headers": ["X-Request-ID", "X-RateLimit-Remaining"],
            "max_age": 600,  # 10 minutes
        }

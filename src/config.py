from typing import Optional
from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings
from decouple import config


class Settings(BaseSettings):
    # Database
    database_url: str = str(
        config(
            "DATABASE_URL",
            cast=str,
            default="postgresql+asyncpg://admin:password@localhost:5432/certificate_db",
        )
    )
    database_pool_size: int = 20
    database_max_overflow: int = 10

    # Redis
    redis_url: str = "redis://localhost:6379/0"
    redis_cache_ttl_seconds: int = 300  # 5 minutes

    # S3 Storage
    s3_endpoint: str = "http://localhost:4566/"
    s3_access_key: str = "my-access-key"
    s3_secret_key: str = "my-secret-key"
    s3_bucket: str = "certificates"
    s3_region: str = "us-east-1"
    s3_secure: bool = False

    # Security
    secret_key: str = "CHANGE_THIS_IN_PRODUCTION_32_BYTES_MIN"
    hmac_secret_key: str = "CHANGE_THIS_HMAC_SECRET_KEY"
    session_token_ttl_seconds: int = 900  # 15 minutes max

    # QR Configuration  
    qr_base_url: str = "http://localhost:8000/api"

    # Rate Limiting
    rate_limit_requests: int = 10
    rate_limit_window_seconds: int = 60

    # API
    api_host: str = "0.0.0.0"
    api_port: int = 8000
    api_workers: int = 4

    # Logging
    log_level: str = "INFO"
    audit_log_enabled: bool = True

    # Admin creation settings
    initial_admin_secret: str = Field(default="CHANGE_ME_INITIAL_SECRET", env="INITIAL_ADMIN_SECRET")  # type: ignore
    emergency_admin_code: str = Field(default="CHANGE_ME_EMERGENCY_CODE", env="EMERGENCY_ADMIN_CODE")  # type: ignore
    system_secret: str = Field(default=str("CHANGE_ME_SYSTEM_SECRET"), env=str("SYSTEM_SECRET"))  # type: ignore
    admin_creation_token_ttl: int = Field(default=3600, env="ADMIN_CREATION_TOKEN_TTL")  # type: ignore
    creation_token: str = str(
        config(
            "CREATION_TOKEN",
            cast=str,
            default=str("creation_token"),
        )
    )  # type: ignore

    token_ttl_seconds: int = 15000

    class Config:
        env_file = ".env"
        case_sensitive = False


@lru_cache()
def get_settings() -> Settings:
    return Settings()

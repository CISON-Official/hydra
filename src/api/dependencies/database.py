from typing import Any, AsyncGenerator
from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine

from src.services.admin_service import AdminService
from src.core.cache.redis_manager import RedisManager
from src.services.audit_service import AuditService
from src.config import get_settings

settings = get_settings()


engine = create_async_engine(
    settings.database_url,
    pool_size=settings.database_pool_size,
    max_overflow=settings.database_max_overflow,
    echo=False,
)
async_session_maker = async_sessionmaker(
    bind=engine, class_=AsyncSession, expire_on_commit=False
)

redis_manager = RedisManager(settings.redis_url, settings.redis_cache_ttl_seconds)
audit_service = AuditService(redis_manager, async_session_maker)


async def get_db() -> AsyncGenerator:
    """Dependency for database session"""
    async with async_session_maker() as session:
        try:
            yield session
            await session.commit()
        except Exception:
            await session.rollback()
            raise
        finally:
            await session.close()


def get_audit_service() -> AuditService:
    return audit_service
    


def get_admin_service():
    return AdminService(audit_service)

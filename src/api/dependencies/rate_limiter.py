from fastapi import Request, HTTPException

from src.config import get_settings
from src.core.cache.redis_manager import RedisManager
from src.api.dependencies.database import redis_manager

settings = get_settings()


class RateLimiter:
    def __init__(self, redis: RedisManager = redis_manager):
        self.redis = redis

    async def check_limit(self, client_ip: str) -> bool:
        return await self.redis.check_rate_limit(
            f"verify:{client_ip}",
            settings.rate_limit_requests,
            settings.rate_limit_window_seconds,
        )

    async def __call__(self, request: Request):
        client_ip = request.client.host  # type: ignore
        if not await self.check_limit(client_ip):
            raise HTTPException(status_code=429, detail="Rate limit exceeded")
        return True


def get_ratelimiter():
    redis_manager = RedisManager(settings.redis_url, settings.redis_cache_ttl_seconds)
    return RateLimiter(redis_manager)

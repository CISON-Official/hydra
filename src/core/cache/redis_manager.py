import json
from typing import Optional, Any
from redis.asyncio import Redis, ConnectionPool
from datetime import datetime, timezone

from src.config import get_settings


class RedisManager:
    def __init__(self, redis_url: str, default_ttl: int = 300):
        self.pool = ConnectionPool.from_url(redis_url, decode_responses=True)
        self.client = Redis(connection_pool=self.pool)
        self.default_ttl = default_ttl

    async def cache_certificate_status(
        self,
        certificate_id: str,
        is_valid: bool,
        expires_at: datetime,
        data: Optional[dict] = None,
        ttl: Optional[int] = None,
    ):
        """Cache certificate validity status"""
        ttl = ttl or self.default_ttl
        await self.client.setex(f"cert:status:{certificate_id}", ttl, json.dumps(data))

    async def get_certificate_status(self, certificate_id: str) -> Optional[dict]:
        """Get cached certificate status"""
        data = await self.client.get(f"cert:status:{certificate_id}")
        if data:
            return json.loads(data)
        return None

    async def invalidate_certificate(self, certificate_id: str):
        """Invalidate all cache entries for a certificate"""
        await self.client.delete(f"cert:status:{certificate_id}")

    async def check_rate_limit(
        self, key: str, max_requests: int, window_seconds: int
    ) -> bool:
        """Sliding window rate limiter"""
        now = datetime.now(timezone.utc).timestamp()
        window_key = f"rate_limit:{key}"

        # Remove old entries
        await self.client.zremrangebyscore(window_key, 0, now - window_seconds)

        # Count current requests
        current_count = await self.client.zcard(window_key)

        if current_count >= max_requests:
            return False

        # Add current request
        await self.client.zadd(window_key, {str(now): now})
        await self.client.expire(window_key, window_seconds)
        return True

    async def close(self):
        await self.client.close()
        await self.pool.disconnect()


def get_redis() -> RedisManager:
    return RedisManager(
        get_settings().redis_url, get_settings().redis_cache_ttl_seconds
    )

from datetime import datetime, timezone, timedelta
from typing import Union, Optional
from enum import Enum

class TTLStrategy(Enum):
    """TTL calculation strategies"""
    MIN = "min"
    MAX = "max"
    DYNAMIC = "dynamic"
    FIXED = "fixed"

class TTLCalculator:
    """Calculate Time-To-Live values for cache entries and session tokens"""
    
    def __init__(
        self, 
        default_max_ttl: int = 3600,  # 1 hour
        default_min_ttl: int = 60,    # 1 minute
        safety_margin_seconds: int = 300  # 5 minutes
    ):
        self.default_max_ttl = default_max_ttl
        self.default_min_ttl = default_min_ttl
        self.safety_margin = safety_margin_seconds
    
    def calculate_session_ttl(
        self,
        certificate_expiry: datetime,
        max_allowed_ttl: Optional[int] = None,
        min_allowed_ttl: Optional[int] = None
    ) -> int:
        """
        Calculate session TTL based on certificate expiry
        
        Args:
            certificate_expiry: When the certificate expires
            max_allowed_ttl: Maximum TTL in seconds (default: 900 = 15 min)
            min_allowed_ttl: Minimum TTL in seconds (default: 60 = 1 min)
        
        Returns:
            TTL in seconds
        """
        max_ttl = max_allowed_ttl or 900  # 15 minutes default
        min_ttl = min_allowed_ttl or 60   # 1 minute default
        
        now = datetime.now(timezone.utc)
        remaining = (certificate_expiry - now).total_seconds()
        
        # Add safety margin
        remaining -= self.safety_margin
        
        if remaining <= 0:
            return 0
        
        # TTL = min(max_ttl, remaining)
        ttl = min(max_ttl, remaining)
        
        # Ensure minimum TTL
        return max(min_ttl, ttl) # type: ignore
    
    def calculate_cache_ttl(
        self,
        certificate_expiry: Optional[datetime] = None,
        strategy: TTLStrategy = TTLStrategy.DYNAMIC,
        custom_ttl: Optional[int] = None
    ) -> int:
        """
        Calculate cache TTL for certificate status
        
        Args:
            certificate_expiry: When the certificate expires (for DYNAMIC strategy)
            strategy: TTL calculation strategy
            custom_ttl: Custom TTL for FIXED strategy
        
        Returns:
            TTL in seconds
        """
        if strategy == TTLStrategy.FIXED and custom_ttl:
            return custom_ttl
        
        if strategy == TTLStrategy.MIN:
            return self.default_min_ttl
        
        if strategy == TTLStrategy.MAX:
            return self.default_max_ttl
        
        if strategy == TTLStrategy.DYNAMIC and certificate_expiry:
            now = datetime.now(timezone.utc)
            remaining = (certificate_expiry - now).total_seconds()
            
            # Cache for 20% of remaining time, but within min/max bounds
            dynamic_ttl = max(
                self.default_min_ttl,
                min(self.default_max_ttl, remaining * 0.2)
            )
            return int(dynamic_ttl)
        
        # Default to 5 minutes
        return 300
    
    def calculate_qr_code_ttl(self) -> int:
        """
        QR codes don't expire, but we might cache them
        Returns TTL for QR code cache (1 year)
        """
        return 365 * 24 * 3600  # 1 year
    
    def get_layered_ttl(self, ttl_seconds: int) -> dict:
        """
        Get TTL in different time units for logging/display
        
        Args:
            ttl_seconds: TTL in seconds
        
        Returns:
            Dictionary with TTL in various units
        """
        return {
            "seconds": ttl_seconds,
            "minutes": round(ttl_seconds / 60, 2),
            "hours": round(ttl_seconds / 3600, 2),
            "days": round(ttl_seconds / 86400, 2),
            "human_readable": self._format_duration(ttl_seconds)
        }
    
    def _format_duration(self, seconds: int) -> str:
        """Format seconds into human-readable duration"""
        if seconds < 60:
            return f"{seconds} seconds"
        elif seconds < 3600:
            minutes = seconds // 60
            remaining_seconds = seconds % 60
            return f"{minutes} minute{'s' if minutes != 1 else ''}" + (
                f" {remaining_seconds} seconds" if remaining_seconds > 0 else ""
            )
        elif seconds < 86400:
            hours = seconds // 3600
            remaining_minutes = (seconds % 3600) // 60
            return f"{hours} hour{'s' if hours != 1 else ''}" + (
                f" {remaining_minutes} minute{'s' if remaining_minutes != 1 else ''}" 
                if remaining_minutes > 0 else ""
            )
        else:
            days = seconds // 86400
            remaining_hours = (seconds % 86400) // 3600
            return f"{days} day{'s' if days != 1 else ''}" + (
                f" {remaining_hours} hour{'s' if remaining_hours != 1 else ''}"
                if remaining_hours > 0 else ""
            )
    
    def should_refresh_cache(
        self,
        cached_at: datetime,
        ttl_seconds: int,
        refresh_threshold: float = 0.7
    ) -> bool:
        """
        Determine if cache should be refreshed early
        
        Args:
            cached_at: When the cache entry was created
            ttl_seconds: Original TTL
            refresh_threshold: Refresh when (elapsed / TTL) >= threshold
        
        Returns:
            True if cache should be refreshed
        """
        now = datetime.now(timezone.utc)
        elapsed = (now - cached_at).total_seconds()
        
        if elapsed >= ttl_seconds:
            return True  # Already expired
        
        return (elapsed / ttl_seconds) >= refresh_threshold

class AdaptiveTTLCalculator(TTLCalculator):
    """TTL calculator that adapts based on access patterns"""
    
    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self.access_history = {}  # In production, use Redis
    
    def record_access(self, certificate_id: str):
        """Record access to certificate for adaptive TTL"""
        if certificate_id not in self.access_history:
            self.access_history[certificate_id] = []
        
        self.access_history[certificate_id].append(datetime.now(timezone.utc))
        
        # Keep only last 100 accesses
        if len(self.access_history[certificate_id]) > 100:
            self.access_history[certificate_id] = self.access_history[certificate_id][-100:]
    
    def calculate_adaptive_cache_ttl(
        self,
        certificate_id: str,
        certificate_expiry: datetime
    ) -> int:
        """
        Calculate cache TTL based on historical access patterns
        Frequently accessed certificates get longer TTL
        """
        base_ttl = super().calculate_cache_ttl(certificate_expiry)
        
        # Get access frequency
        history = self.access_history.get(certificate_id, [])
        if len(history) < 10:
            return base_ttl
        
        # Calculate average interval between accesses
        intervals = []
        for i in range(1, len(history)):
            interval = (history[i] - history[i-1]).total_seconds()
            intervals.append(interval)
        
        if intervals:
            avg_interval = sum(intervals) / len(intervals)
            # Scale TTL based on access frequency (max 2x)
            multiplier = min(2.0, max(1.0, 3600 / avg_interval if avg_interval > 0 else 1))
            adaptive_ttl = int(base_ttl * multiplier)
            return min(self.default_max_ttl, adaptive_ttl)
        
        return base_ttl
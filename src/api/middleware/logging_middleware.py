import time
import logging
from fastapi import Request, Response
from starlette.middleware.base import BaseHTTPMiddleware
from typing import Callable
import json
from uuid import uuid4

logger = logging.getLogger("certificate_platform")


class LoggingMiddleware(BaseHTTPMiddleware):
    def __init__(self, app, log_headers: bool = False, log_body: bool = False):
        super().__init__(app)
        self.log_headers = log_headers
        self.log_body = log_body

    async def dispatch(self, request: Request, call_next: Callable) -> Response:
        # Generate request ID
        request_id = str(uuid4())
        request.state.request_id = request_id

        # Start timer
        start_time = time.time()

        # Log request
        await self._log_request(request, request_id)

        # Process request
        try:
            response = await call_next(request)

            # Calculate duration
            duration = time.time() - start_time

            # Log response
            await self._log_response(response, request, request_id, duration)

            # Add request ID header
            response.headers["X-Request-ID"] = request_id

            return response

        except Exception as e:
            duration = time.time() - start_time
            logger.error(
                f"Request failed: {request.method} {request.url.path} | "
                f"Request-ID: {request_id} | Duration: {duration:.3f}s | "
                f"Error: {str(e)}"
            )
            raise

    async def _log_request(self, request: Request, request_id: str):
        """Log incoming request details"""
        log_data = {
            "request_id": request_id,
            "method": request.method,
            "path": request.url.path,
            "query_params": str(request.query_params),
            "client_ip": request.client.host if request.client else "unknown",
            "user_agent": request.headers.get("user-agent", "unknown"),
        }

        if self.log_headers:
            # Filter sensitive headers
            safe_headers = {
                k: v
                for k, v in request.headers.items()
                if k.lower() not in ["authorization", "cookie", "x-api-key"]
            }
            log_data["headers"] = safe_headers  # type: ignore

        # Log at INFO level for API requests, DEBUG for others
        if request.url.path.startswith(("/api", "/verify")):
            logger.info(f"Request: {json.dumps(log_data)}")
        else:
            logger.debug(f"Request: {json.dumps(log_data)}")

    async def _log_response(
        self, response: Response, request: Request, request_id: str, duration: float
    ):
        """Log response details"""
        log_data = {
            "request_id": request_id,
            "method": request.method,
            "path": request.url.path,
            "status_code": response.status_code,
            "duration_ms": round(duration * 1000, 2),
        }

        # Log warnings for slow requests
        if duration > 1.0:  # > 1 second
            logger.warning(f"Slow request: {json.dumps(log_data)}")
        elif response.status_code >= 400:
            logger.warning(f"Error response: {json.dumps(log_data)}")
        else:
            logger.info(f"Response: {json.dumps(log_data)}")


class PerformanceLoggingMiddleware(BaseHTTPMiddleware):
    """Middleware specifically for performance monitoring"""

    async def dispatch(self, request: Request, call_next: Callable) -> Response:
        # Track different phases
        timings = {}

        # Phase 1: Request parsing
        parse_start = time.time()
        # Let the request be processed
        timings["parse"] = time.time() - parse_start

        # Phase 2: Route matching (handled by FastAPI)
        route_start = time.time()
        response = await call_next(request)
        timings["route_processing"] = time.time() - route_start

        # Phase 3: Response serialization
        serialize_start = time.time()
        # Response is already generated
        timings["response_serialization"] = time.time() - serialize_start

        # Phase 4: Total
        timings["total"] = sum(timings.values())

        # Log performance metrics
        if timings["total"] > 0.5:  # > 500ms
            logger.warning(
                f"Performance metrics for {request.method} {request.url.path}: "
                f"{json.dumps(timings)}"
            )

        # Add timing headers for debugging
        response.headers["X-Request-Duration-MS"] = str(
            round(timings["total"] * 1000, 2)
        )

        return response

import hashlib
from pathlib import Path
from typing import BinaryIO, Union, Tuple, List

import aiofiles


class HashValidator:
    """Validate file integrity using SHA-256 hashes"""

    def __init__(self, algorithm: str = "sha256"):
        self.algorithm = algorithm
        self.hash_func = getattr(hashlib, algorithm)

    def compute_hash(self, data: bytes) -> str:
        """Compute hash from bytes"""
        return self.hash_func(data).hexdigest()

    async def compute_hash_async(self, file_path: Union[str, Path]) -> str:
        """Compute hash of file asynchronously"""
        hash_obj = self.hash_func()

        async with aiofiles.open(file_path, "rb") as f:
            while chunk := await f.read(8192):
                hash_obj.update(chunk)

        return hash_obj.hexdigest()

    def compute_hash_chunked(self, data: bytes, chunk_size: int = 8192) -> str:
        """Compute hash in chunks for large files"""
        hash_obj = self.hash_func()

        for i in range(0, len(data), chunk_size):
            chunk = data[i : i + chunk_size]
            hash_obj.update(chunk)

        return hash_obj.hexdigest()

    def validate_integrity(self, data: bytes, expected_hash: str) -> bool:
        """Validate data against expected hash"""
        computed_hash = self.compute_hash(data)
        return computed_hash == expected_hash

    async def validate_file_integrity(
        self, file_path: Union[str, Path], expected_hash: str
    ) -> bool:
        """Validate file integrity asynchronously"""
        computed_hash = await self.compute_hash_async(file_path)
        return computed_hash == expected_hash

    def generate_merkle_proof(
        self, data: bytes, leaf_index: int, total_leaves: int
    ) -> Tuple[str, List[str]]:
        """
        Generate Merkle proof for a data chunk

        Args:
            data: The data chunk
            leaf_index: Index of the leaf (0-based)
            total_leaves: Total number of leaves in the tree

        Returns:
            Tuple of (root_hash, proof_hashes)
        """
        # Create leaf hashes
        leaves = []
        chunk_size = len(data) // total_leaves if total_leaves > 1 else len(data)

        for i in range(total_leaves):
            start = i * chunk_size
            end = start + chunk_size if i < total_leaves - 1 else len(data)
            chunk = data[start:end]
            leaves.append(self.compute_hash(chunk))

        # Build Merkle tree
        tree = [leaves]
        while len(tree[-1]) > 1:
            level = []
            for i in range(0, len(tree[-1]), 2):
                if i + 1 < len(tree[-1]):
                    combined = tree[-1][i] + tree[-1][i + 1]
                else:
                    combined = tree[-1][i] + tree[-1][i]
                level.append(self.compute_hash(combined.encode()))
            tree.append(level)

        root_hash = tree[-1][0]

        # Build proof for leaf_index
        proof = []
        current_index = leaf_index

        for level in range(len(tree) - 1):
            sibling_index = (
                current_index + 1 if current_index % 2 == 0 else current_index - 1
            )
            if sibling_index < len(tree[level]):
                proof.append(tree[level][sibling_index])
            current_index = current_index // 2

        return root_hash, proof

    def verify_merkle_proof(
        self,
        data: bytes,
        leaf_index: int,
        proof: List[str],
        expected_root: str,
        total_leaves: int,
    ) -> bool:
        """Verify Merkle proof for a data chunk"""
        chunk_size = len(data) // total_leaves if total_leaves > 1 else len(data)

        # Compute leaf hash
        start = leaf_index * chunk_size
        end = start + chunk_size if leaf_index < total_leaves - 1 else len(data)
        chunk = data[start:end]
        current_hash = self.compute_hash(chunk)

        # Recompute root hash using proof
        current_index = leaf_index
        for sibling_hash in proof:
            if current_index % 2 == 0:
                combined = current_hash + sibling_hash
            else:
                combined = sibling_hash + current_hash
            current_hash = self.compute_hash(combined.encode())
            current_index = current_index // 2

        return current_hash == expected_root


class SecureHashValidator(HashValidator):
    """Extended hash validator with salt support for additional security"""

    def __init__(self, salt: bytes | None = None, algorithm: str = "sha256"):
        super().__init__(algorithm)
        self.salt = salt or b""

    def compute_salted_hash(self, data: bytes) -> str:
        """Compute hash with salt"""
        salted_data = self.salt + data
        return self.compute_hash(salted_data)

    def validate_salted_integrity(self, data: bytes, expected_hash: str) -> bool:
        """Validate with salt"""
        computed_hash = self.compute_salted_hash(data)
        return computed_hash == expected_hash

    def verify_multipart_hash(
        self, data: bytes, expected_hash: str, part_size: int = 1024 * 1024  # 1MB parts
    ) -> bool:
        """
        Verify hash of large file by computing in parts
        Useful for streaming verification
        """
        hash_obj = self.hash_func()

        for i in range(0, len(data), part_size):
            part = data[i : i + part_size]
            hash_obj.update(part)

        computed_hash = hash_obj.hexdigest()
        return computed_hash == expected_hash


def get_hash_validator():
    return HashValidator()

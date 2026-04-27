#!/bin/bash
# ================================================
# PostgreSQL Setup Script
# Creates a user, a database, and grants full permissions
# ================================================

set -e  # Exit immediately if any command fails


DB_USER="${DB_USER:-admin}" # Change this to a valid username
DB_PASSWORD="${DB_PASSWORD:-password}"   # Change this to a strong password!
DB_NAME="${DB_NAME:-certificate_db}"

# You can also set them via command line arguments
if [ $# -ge 1 ]; then DB_USER="$1"; fi
if [ $# -ge 2 ]; then DB_PASSWORD="$2"; fi
if [ $# -ge 3 ]; then DB_NAME="$3"; fi

echo "=== PostgreSQL Database Setup ==="
echo "User     : $DB_USER"
echo "Database : $DB_NAME"
echo "================================="


echo "→ Creating user '$DB_USER'..."
sudo -u postgres psql -c "
    CREATE USER $DB_USER WITH PASSWORD '$DB_PASSWORD' LOGIN;
" 2>/dev/null || echo "User '$DB_USER' already exists (skipping creation)."


echo "→ Creating database '$DB_NAME'..."
sudo -u postgres createdb -O "$DB_USER" "$DB_NAME" 2>/dev/null || \
sudo -u postgres psql -c "CREATE DATABASE $DB_NAME;"

# Grant all privileges on the database
echo "→ Granting ALL privileges on database '$DB_NAME' to user '$DB_USER'..."
sudo -u postgres psql -c "
    GRANT ALL PRIVILEGES ON DATABASE $DB_NAME TO $DB_USER;
    GRANT ALL ON SCHEMA public TO $DB_USER;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO $DB_USER;
    ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO $DB_USER;
"

echo "✅ Done!"
echo ""
echo "Your database is ready."
echo "Connection URL example:"
echo "postgresql+asyncpg://$DB_USER:$DB_PASSWORD@localhost:5432/$DB_NAME"
echo ""
echo "Remember to update your .env file with the correct password!"
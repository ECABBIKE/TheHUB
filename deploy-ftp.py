#!/usr/bin/env python3
"""
FTP Deployment Script for TheHUB to InfinityFree
Deploys all project files to InfinityFree hosting via FTP
"""

import ftplib
import os
import sys
from pathlib import Path

# FTP Configuration
FTP_HOST = "ftpupload.net"
FTP_USER = "if0_40400950"
FTP_PASS = "qv19oAyv44J2xX"
FTP_DIR = "/htdocs/"

# Files and directories to exclude
EXCLUDE = {
    '.git', '.gitignore', 'node_modules', 'vendor', '.DS_Store',
    'Thumbs.db', '.backup', 'backup', 'deploy-ftp.py',
    'AUDIT_REPORT.md', 'SECURITY.md', '.github'
}

# File extensions to exclude
EXCLUDE_EXT = {'.backup', '.bak', '.tmp'}

def should_exclude(path):
    """Check if file/directory should be excluded"""
    parts = Path(path).parts

    # Check if any part of path is in exclude list
    for part in parts:
        if part in EXCLUDE:
            return True

    # Check file extension
    if Path(path).suffix in EXCLUDE_EXT:
        return True

    return False

def create_remote_directory(ftp, remote_dir):
    """Create remote directory if it doesn't exist"""
    try:
        ftp.cwd(remote_dir)
    except ftplib.error_perm:
        # Directory doesn't exist, create it
        parts = remote_dir.strip('/').split('/')
        current = '/'
        for part in parts:
            if not part:
                continue
            current = current.rstrip('/') + '/' + part
            try:
                ftp.cwd(current)
            except ftplib.error_perm:
                try:
                    ftp.mkd(current)
                    print(f"  📁 Created directory: {current}")
                    ftp.cwd(current)
                except ftplib.error_perm as e:
                    print(f"  ⚠️  Could not create {current}: {e}")

def upload_file(ftp, local_path, remote_path):
    """Upload a single file to FTP server"""
    try:
        with open(local_path, 'rb') as f:
            ftp.storbinary(f'STOR {remote_path}', f)
        return True
    except Exception as e:
        print(f"  ❌ Error uploading {local_path}: {e}")
        return False

def deploy_directory(ftp, local_dir, remote_dir):
    """Recursively deploy directory contents"""
    file_count = 0
    error_count = 0

    for root, dirs, files in os.walk(local_dir):
        # Remove excluded directories from traversal
        dirs[:] = [d for d in dirs if not should_exclude(os.path.join(root, d))]

        # Calculate relative path
        rel_path = os.path.relpath(root, local_dir)
        if rel_path == '.':
            current_remote = remote_dir
        else:
            current_remote = remote_dir.rstrip('/') + '/' + rel_path.replace(os.sep, '/')

        # Create remote directory
        create_remote_directory(ftp, current_remote)

        # Upload files in current directory
        for filename in files:
            local_file = os.path.join(root, filename)

            # Skip excluded files
            if should_exclude(local_file):
                continue

            remote_file = filename

            # Upload file
            print(f"  ⬆️  Uploading: {os.path.relpath(local_file, local_dir)}")

            try:
                ftp.cwd(current_remote)
                if upload_file(ftp, local_file, remote_file):
                    file_count += 1
                else:
                    error_count += 1
            except Exception as e:
                print(f"  ❌ Error: {e}")
                error_count += 1

    return file_count, error_count

def create_env_file(ftp):
    """Create .env file on remote server"""
    env_content = """APP_ENV=production
APP_DEBUG=false
ADMIN_USERNAME=admin
ADMIN_PASSWORD=qv19oAyv44J2xX
DB_HOST=sql123.infinityfree.com
DB_NAME=if0_40400950_THEHUB
DB_USER=if0_40400950
DB_PASS=qv19oAyv44J2xX
DB_CHARSET=utf8mb4
SESSION_NAME=thehub_session
SESSION_LIFETIME=86400
MAX_UPLOAD_SIZE=10485760
ALLOWED_EXTENSIONS=xlsx,xls,csv
FORCE_HTTPS=false
DISPLAY_ERRORS=false
"""

    try:
        ftp.cwd(FTP_DIR)
        # Create temporary file
        temp_file = '/tmp/thehub_env'
        with open(temp_file, 'w') as f:
            f.write(env_content)

        # Upload as .env
        with open(temp_file, 'rb') as f:
            ftp.storbinary('STOR .env', f)

        os.remove(temp_file)
        print("  ✅ Created .env file on remote server")
        return True
    except Exception as e:
        print(f"  ❌ Error creating .env: {e}")
        return False

def main():
    """Main deployment function"""
    print("🚀 TheHUB FTP Deployment to InfinityFree")
    print("=" * 60)

    # Get project directory
    project_dir = os.path.dirname(os.path.abspath(__file__))
    print(f"📁 Project directory: {project_dir}")
    print(f"🌐 FTP Server: {FTP_HOST}")
    print(f"📂 Remote directory: {FTP_DIR}")
    print()

    try:
        # Connect to FTP server
        print("🔌 Connecting to FTP server...")
        ftp = ftplib.FTP(FTP_HOST, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
        print("  ✅ Connected successfully!")
        print()

        # Create .env file
        print("📝 Creating .env file on remote server...")
        create_env_file(ftp)
        print()

        # Deploy files
        print("📦 Deploying files...")
        file_count, error_count = deploy_directory(ftp, project_dir, FTP_DIR)
        print()

        # Close connection
        ftp.quit()

        # Summary
        print("=" * 60)
        print("✅ Deployment Complete!")
        print(f"📊 Files uploaded: {file_count}")
        if error_count > 0:
            print(f"⚠️  Errors: {error_count}")
        print()
        print("🌐 Your site should now be live at:")
        print("   https://thehub.infinityfreeapp.com/")
        print("   https://thehub.infinityfreeapp.com/admin/login.php")
        print()

        return 0 if error_count == 0 else 1

    except ftplib.error_perm as e:
        print(f"❌ FTP Permission Error: {e}")
        return 1
    except ftplib.error_temp as e:
        print(f"❌ FTP Temporary Error: {e}")
        return 1
    except Exception as e:
        print(f"❌ Unexpected Error: {e}")
        import traceback
        traceback.print_exc()
        return 1

if __name__ == "__main__":
    sys.exit(main())

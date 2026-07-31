#!/bin/bash

# Super Admin Setup Script
# Run this script to set up the role hierarchy and Super Admin user

echo "🚀 EkklesiaSoft - Super Admin Setup"
echo "===================================="
echo ""

# Navigate to project directory
cd "$(dirname "$0")"

echo "📊 Step 1: Running migrations..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo "✅ Migrations completed successfully!"
else
    echo "❌ Migration failed! Please check your database connection."
    exit 1
fi

echo ""
echo "🌱 Step 2: Seeding roles and Super Admin user..."
php artisan db:seed --class=RolesTableSeeder
php artisan db:seed --class=SuperAdminUserSeeder

if [ $? -eq 0 ]; then
    echo "✅ Database seeded successfully!"
else
    echo "❌ Seeding failed! Please check the error above."
    exit 1
fi

echo ""
echo "🧹 Step 3: Clearing cache..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear

echo ""
echo "✅ Setup complete!"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  Super Admin Credentials"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "  📧 Email: franklininnocent.fs@gmail.com"
echo "  🔑 Password: Secrete*999"
echo "  👤 Role: SuperAdmin"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""
echo "🌐 Next steps:"
echo "  1. Start Laravel: php artisan serve"
echo "  2. Test login at: http://127.0.0.1:8000/api/auth/login"
echo "  3. Or use Angular app: http://localhost:4200/auth/login"
echo ""
echo "🎉 You're all set!"


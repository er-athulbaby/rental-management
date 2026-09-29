$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build

# Release tag for the footer and /health; must exist before optimize (config:cache reads it).
git fetch --tags --quiet || true
git describe --tags --always > VERSION

$FORGE_PHP artisan optimize
$FORGE_PHP artisan migrate --database=migrator --force
$FORGE_PHP artisan permission:cache-reset

$ACTIVATE_RELEASE()

$FORGE_PHP artisan queue:restart

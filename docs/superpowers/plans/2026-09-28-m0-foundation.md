# M0 Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Laravel 13 Livewire starter kit into the secured foundation of the Rental Management System: two-user MySQL 26.7 database, hardened login with mandatory 2FA, roles and permissions, an append-only audit log, company settings, gapless document numbers, building scoping, private documents, install and support commands, an Arabic-capable PDF renderer, backups with restore checks, CI and a deploy runbook.

**Architecture:** One Laravel app per rental company (spec D1). Thin Livewire components and controllers call `app/Actions/*` classes; each Action runs in one database transaction, is the only write path for its records, and writes explicit audit entries where model events are not enough. The application connects as `rms_app` (DML + EXECUTE only); migrations, triggers and backups use the `migrator` connection (`rms_migrate`). Tests run on real MySQL as the app user.

**Tech Stack:** PHP 8.5, Laravel 13.x, Livewire 4, Flux 2 (free only), Fortify 1.40, MySQL 26.7, Pest 5, Larastan 3 (level 8), Pint, spatie/laravel-permission ^8, spatie/laravel-activitylog ^5, spatie/laravel-backup ^10, wnx/laravel-backup-restore ^1.9, mpdf/mpdf ^8.3 + mpdf/qrcode, sentry/sentry-laravel ^4.

**Spec:** `docs/superpowers/specs/2026-09-28-rental-management-v1-design.md` — this plan implements the §15 **M0** row, plus the parts of §2, §3, §8, §9, §12, §13 and §14 that M0 needs. Read the spec sections a task names before starting it.

## Global Constraints

- Latest stable versions, pinned (D16): Laravel 13.x, PHP 8.5, MySQL 26.7 (Innovation track), Livewire ^4, Flux ^2 free, Pest ^5; composer.json `"php": "^8.5"`.
- One codebase for every company; no company-specific code (D13).
- No Redis: `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database` (D14).
- Timezone fixed to `Asia/Bahrain` (§3).
- Money columns are `DECIMAL(12,3)`; money arithmetic in PHP uses integer fils, never floats (§2). (M0 stores only the VAT rate, as `DECIMAL(5,2)`.)
- Two database users (§8.5): `rms_app` = SELECT, INSERT, UPDATE, DELETE, EXECUTE; `rms_migrate` = all privileges on the schema. Every MySQL server and CI run has `log_bin_trust_function_creators = 1`.
- One SQL statement per `DB::unprepared()` call; triggers raise `SIGNAL SQLSTATE '45000'`.
- Actions: one DB transaction each, the only write path for their records, never mass-update audited models (§2).
- The audit log never contains `password`, `remember_token`, `two_factor_secret` or `two_factor_recovery_codes` (§8.4).
- Application UI in English; every staff screen usable at 375 px width (§2, D5).
- Only free Flux components: use native `input type=date|file` and native `flux:select` (§2).
- Permission names are exactly the 25 in spec §8.1.
- Tests: Pest 5 on real MySQL 26.7 (never SQLite), run as `rms_app` (§14).
- Uploads: pdf, jpg, jpeg, png, webp, docx, xlsx only; 10 MB max; stored privately under random names (§8.6).
- `APP_DEBUG=false` in production; users see "Something went wrong. Please try again." (§13.4).

## Working environment (every command block assumes this)

```bash
export PHP="/c/Users/ababy/.config/herd/bin/php85/php.exe"
export COMPOSER="$PHP /c/Users/ababy/.config/herd/bin/composer.phar"
export MYSQL="/c/Users/ababy/.mysql/mysql-26.7.0-winx64/bin/mysql.exe"
cd /c/Users/ababy/Documents/RentalManagementSystem
```

Local MySQL 26.7 listens on 127.0.0.1:3306 (root, no password). If it isn't running, start it with `cmd //c "%USERPROFILE%\.mysql\start-mysql.cmd"`.

## File map (what M0 creates)

```
app/
  Actions/
    Documents/{StoreDocument,DeleteDocument}.php
    Fortify/{ResetUserPassword,DisableTwoFactorAuthentication}.php
    Roles/SyncRolePermissions.php
    Settings/UpdateCompanySettings.php
    Users/{CreateUser,UpdateUserProfile,SyncUserRoles,SyncUserBuildings,DeactivateUser,ReactivateUser,ResetUserTwoFactor}.php
    {NextDocumentNumber,EnsureNumberSequences}.php
  Audit/{Audit,LogActivityAction,AuthEventSubscriber}.php
  Backup/RestoredBackupIsSane.php
  Console/Commands/{InstallCommand,VendorSupportCommand,SetInstallSetting,EnsureNumberSequencesCommand}.php
  Enums/{PermissionName,RoleName,TaxCategory,ProrationBasis,NumberSequenceKey,DocumentCategory,BuildingType}.php
  Http/Controllers/DocumentController.php
  Http/Middleware/{EnsureUserIsActive,EnsureTwoFactorIsConfirmed}.php
  Livewire/Admin/{CompanySettings,AuditLog}.php, Livewire/Admin/Users/{Index,Form}.php, Livewire/Admin/Roles/{Index,Edit}.php
  Models/{User,Building,CompanySetting,Document}.php
  Notifications/{SensitiveAccessGranted,UserEmailChanged}.php
  Pdf/PdfRenderer.php
  Policies/{BuildingPolicy,DocumentPolicy,UserPolicy}.php
  Support/Approvers.php
database/migrations/2026_09_28_000{100..700}_*.php, database/seeders/RolesAndPermissionsSeeder.php, database/factories/BuildingFactory.php
routes/{web,settings,admin,console}.php
resources/fonts/IBMPlexSansArabic-{Regular,Bold}.ttf, resources/views/{livewire/admin/**,pdf/spike,errors/500}.blade.php
tests/Feature/**, tests/Concurrency/**
deploy/{provision-mysql.sh,forge-deploy.sh,README.md}, .github/workflows/ci.yml
```

## Tasks that need the product owner

Task 20's staging steps need a Laravel Forge account (Business plan), a VPS and an S3-compatible bucket; Task 16's Sentry DSN needs a Sentry account; Task 19 needs a GitHub repository. Everything else runs on the local machine. When a step needs one of these, stop and ask.

---
### Task 1: Scaffold on MySQL with two database users

**Spec:** §2 (runtime, DB users), §3 (timezone), §8.5 (two users), §14 (tests on MySQL as the app user)

**Files:**
- Create: whole Laravel 13 Livewire starter kit (copied from the installer output), `.env`, `.env.testing`, `tests/Feature/Database/AppUserGrantsTest.php`, `tests/Concurrency/.gitkeep`
- Modify: `composer.json`, `config/app.php`, `config/database.php`, `.env.example`, `.gitignore`, `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`
- Delete: `database/database.sqlite`

**Interfaces:**
- Consumes: nothing
- Produces: DB connections `mysql` (as `rms_app`) and `migrator` (as `rms_migrate`); env vars `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD`; `Tests\TestCase` (migrates as migrator, `withoutVite()`, `$connectionsToTruncate = ['migrator']`); Pest suites `Feature` (RefreshDatabase) and `Concurrency` (DatabaseTruncation)

- [ ] **Step 1: Scaffold the kit into a temporary folder and copy it into the repo**

```bash
cd /c/Users/ababy/Documents
"$PHP" /c/Users/ababy/.config/herd/bin/laravel.phar new rms-scaffold \
  --livewire --livewire-class-components --pest --database=sqlite --no-node --no-boost --no-interaction
cp -r rms-scaffold/. RentalManagementSystem/
rm -rf rms-scaffold
cd RentalManagementSystem
rm -f database/database.sqlite
git status --short | head
```
Expected: the kit's files show as untracked next to `docs/`.

- [ ] **Step 2: Pin PHP 8.5, Bahrain time, and ignore local-only files**

In `composer.json` change `"php": "^8.3"` to `"php": "^8.5"`, then:
```bash
$COMPOSER update --lock --no-interaction
```
In `config/app.php` replace the `'timezone' => ...` line with:
```php
    'timezone' => 'Asia/Bahrain',
```
Append to `.gitignore`:
```
/VERSION
.env.testing
/storage/app/mpdf
```

- [ ] **Step 3: Write the failing grants test**

Create `tests/Feature/Database/AppUserGrantsTest.php`:
```php
<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// A denied DDL statement still implicitly COMMITs the connection's open transaction,
// so probe on a separate connection, never on the RefreshDatabase one.
beforeEach(function () {
    config(['database.connections.grants_probe' => config('database.connections.mysql')]);
});

afterEach(function () {
    DB::purge('grants_probe');
});

it('runs as the app user', function () {
    expect(DB::selectOne('select current_user() as u')->u)
        ->toStartWith(config('database.connections.mysql.username').'@');
});

it('denies DDL to the app user', function (string $sql) {
    expect(fn () => DB::connection('grants_probe')->unprepared($sql))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1142));
})->with([
    'truncate' => 'TRUNCATE TABLE sessions',
    'drop table' => 'DROP TABLE sessions',
    'create trigger' => 'CREATE TRIGGER x BEFORE DELETE ON users FOR EACH ROW SET @a = 1',
    'alter' => 'ALTER TABLE users ADD COLUMN x INT',
    'create table' => 'CREATE TABLE x (id INT)',
])->skip(
    fn () => config('database.connections.mysql.username') === config('database.connections.migrator.username'),
    'app and migrator are the same user',
);
```

- [ ] **Step 4: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Database/AppUserGrantsTest.php`
Expected: FAIL — the app still uses sqlite (`no such function: current_user` or `Database connection [migrator] not configured`).

- [ ] **Step 5: Create the local databases and the two users (passwords never printed)**

```bash
APP_PW=$(openssl rand -hex 16); MIG_PW=$(openssl rand -hex 16)
"$MYSQL" -u root -h 127.0.0.1 <<SQL
CREATE DATABASE IF NOT EXISTS rms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS rms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rms_app'@'%' IDENTIFIED BY '$APP_PW';
CREATE USER IF NOT EXISTS 'rms_migrate'@'%' IDENTIFIED BY '$MIG_PW';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON rms.* TO 'rms_app'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON rms_test.* TO 'rms_app'@'%';
GRANT ALL PRIVILEGES ON rms.* TO 'rms_migrate'@'%';
GRANT ALL PRIVILEGES ON rms_test.* TO 'rms_migrate'@'%';
SQL
"$MYSQL" -u root -h 127.0.0.1 -N -e "SELECT @@log_bin_trust_function_creators"
```
Expected: `1` (set in `%USERPROFILE%\.mysql\my.ini`). If it prints `0`, run `"$MYSQL" -u root -h 127.0.0.1 -e "SET PERSIST log_bin_trust_function_creators = 1"`.

- [ ] **Step 6: Write the credentials into `.env` and `.env.testing`**

```bash
setenv() { local f="$1" k="$2" v="$3"; sed -i "/^#\? *${k}=/d" "$f"; printf '%s=%s\n' "$k" "$v" >> "$f"; }
for f in .env; do
  setenv $f APP_NAME '"Rental Management"'
  setenv $f DB_CONNECTION mysql
  setenv $f DB_HOST 127.0.0.1
  setenv $f DB_PORT 3306
  setenv $f DB_DATABASE rms
  setenv $f DB_USERNAME rms_app
  setenv $f DB_PASSWORD "$APP_PW"
  setenv $f DB_MIGRATOR_USERNAME rms_migrate
  setenv $f DB_MIGRATOR_PASSWORD "$MIG_PW"
  setenv $f SESSION_DRIVER database
  setenv $f CACHE_STORE database
  setenv $f QUEUE_CONNECTION database
  # Local http://rms.test has no TLS; production keeps the secure default (Task 3).
  setenv $f SESSION_SECURE_COOKIE false
done
cp .env .env.testing
setenv .env.testing DB_DATABASE rms_test
setenv .env.testing SESSION_SECURE_COOKIE true
unset APP_PW MIG_PW
grep -c '^APP_KEY=base64' .env.testing
```
Expected: `1` (the app key was copied). Laravel loads `.env.testing` instead of `.env` when `APP_ENV=testing`.

Apply the same keys to `.env.example` with empty passwords:
```bash
for k in "DB_CONNECTION mysql" "DB_HOST 127.0.0.1" "DB_PORT 3306" "DB_DATABASE rms" "DB_USERNAME rms_app" "DB_PASSWORD " \
         "DB_MIGRATOR_USERNAME rms_migrate" "DB_MIGRATOR_PASSWORD " "SESSION_DRIVER database" "CACHE_STORE database" "QUEUE_CONNECTION database"; do
  set -- $k; setenv .env.example "$1" "${2:-}"
done
setenv .env.example APP_NAME '"Rental Management"'
```

- [ ] **Step 7: Add the `migrator` connection**

In `config/database.php`, duplicate the whole `'mysql' => [ ... ],` block directly below it, rename the key to `'migrator'`, and change only these three lines in the copy:
```php
            'url' => env('DB_MIGRATOR_URL'),
            'username' => env('DB_MIGRATOR_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_MIGRATOR_PASSWORD', env('DB_PASSWORD', '')),
```

- [ ] **Step 8: Point the tests at MySQL and migrate as the migrator**

In `phpunit.xml` replace the two sqlite lines with:
```xml
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_DATABASE" value="rms_test"/>
```
and add a third suite inside `<testsuites>`:
```xml
        <testsuite name="Concurrency">
            <directory>tests/Concurrency</directory>
        </testsuite>
```
Replace `tests/TestCase.php` with:
```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /** DatabaseTruncation runs TRUNCATE, which the app user may not do (spec §8.5). */
    protected array $connectionsToTruncate = ['migrator'];

    protected function setUp(): void
    {
        parent::setUp();

        // Views call @vite; tests never need built assets.
        $this->withoutVite();
    }

    /**
     * RefreshDatabase and DatabaseTruncation migrate as the DDL user; tests query as the app user.
     * (Overriding migrateFreshUsing() does not work: the trait Pest mixes in wins over this class.)
     */
    public function artisan($command, $parameters = [])
    {
        if ($command === 'migrate:fresh') {
            $parameters['--database'] ??= 'migrator';
        }

        return parent::artisan($command, $parameters);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
```
Replace the first block of `tests/Pest.php` (the `pest()->extend(...)` call) with:
```php
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Real commits across two connections. DatabaseTruncation only truncates BEFORE a test,
// so truncate after too, or committed rows leak into RefreshDatabase tests.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->afterEach(fn () => $this->truncateTablesForAllConnections())
    ->in('Concurrency');
```
(Remove the now-duplicate `use` lines at the top of the file.) Then `touch tests/Concurrency/.gitkeep`.

- [ ] **Step 9: Migrate and run the tests**

```bash
"$PHP" artisan config:clear
"$PHP" artisan migrate --database=migrator --force
"$PHP" artisan test tests/Feature/Database/AppUserGrantsTest.php
"$PHP" artisan test
```
Expected: the grants test passes (6 tests; dropping a trigger is checked in Task 6, once one exists), and the whole kit suite passes.

- [ ] **Step 10: Build assets and serve locally**

```bash
npm install && npm run build
"/c/Users/ababy/.config/herd/bin/herd.bat" link rms
```
Expected: http://rms.test shows the kit's welcome page.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Scaffold the Laravel 13 Livewire app on MySQL with two database users

The app connects as rms_app (DML and EXECUTE only); migrations and tests'
migrate:fresh use the migrator connection. Tests run on MySQL as the app
user and prove DDL is denied to it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 2: Remove registration, email verification, passkeys and account deletion

**Spec:** §8.6 ("No self-registration; Admin creates users. Users are deactivated, never deleted"; TOTP 2FA only)

**Files:**
- Delete: `app/Actions/Fortify/CreateNewUser.php`, `app/Livewire/Actions/Logout.php`, `app/Livewire/Settings/DeleteUserForm.php`, `resources/views/livewire/settings/delete-user-form.blade.php`, `resources/views/livewire/auth/register.blade.php`, `resources/views/livewire/auth/verify-email.blade.php`, `database/migrations/2024_01_01_000000_create_passkeys_table.php`, `resources/js/passkeys.js`, `resources/views/components/passkey-registration.blade.php`, `resources/views/components/passkey-verify.blade.php`, `tests/Feature/Auth/RegistrationTest.php`, `tests/Feature/Auth/EmailVerificationTest.php`
- Modify: `config/fortify.php`, `app/Providers/FortifyServiceProvider.php`, `app/Models/User.php`, `database/migrations/0001_01_01_000000_create_users_table.php`, `database/factories/UserFactory.php`, `routes/web.php`, `routes/settings.php`, `app/Livewire/Settings/Profile.php`, `app/Livewire/Settings/Security.php`, `resources/views/livewire/settings/profile.blade.php`, `resources/views/livewire/settings/security.blade.php`, `resources/views/livewire/auth/login.blade.php`, `resources/views/livewire/auth/confirm-password.blade.php`, `vite.config.js`, `package.json`, `tests/Feature/Settings/SecurityTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/Auth/PasswordResetTest.php`
- Test: `tests/Feature/Auth/RemovedFeaturesTest.php`

**Interfaces:**
- Consumes: Task 1 test setup
- Produces: `User` without `MustVerifyEmail`, `PasskeyUser`, `PasskeyAuthenticatable`, `email_verified_at`; Fortify features = reset passwords + TOTP 2FA (confirm, confirmPassword)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/RemovedFeaturesTest.php`:
```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

test('no registration, email verification or passkey routes exist', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map->uri();

    expect($uris->filter(fn (string $uri) => Str::contains($uri, ['register', 'email/verif', 'passkey', '.well-known'])))
        ->toBeEmpty();
});

test('the register page is gone', function () {
    $this->get('/register')->assertNotFound();
});

test('the profile page offers no account deletion', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete account');
});

test('the login page has no sign-up link or remember-me box', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('Sign up')
        ->assertDontSee('Remember me');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Auth/RemovedFeaturesTest.php`
Expected: FAIL on all four (register routes exist, "Delete account" and "Remember me" are shown).

- [ ] **Step 3: Delete the files**

```bash
git rm -q app/Actions/Fortify/CreateNewUser.php app/Livewire/Actions/Logout.php \
  app/Livewire/Settings/DeleteUserForm.php resources/views/livewire/settings/delete-user-form.blade.php \
  resources/views/livewire/auth/register.blade.php resources/views/livewire/auth/verify-email.blade.php \
  database/migrations/2024_01_01_000000_create_passkeys_table.php resources/js/passkeys.js \
  resources/views/components/passkey-registration.blade.php resources/views/components/passkey-verify.blade.php \
  tests/Feature/Auth/RegistrationTest.php tests/Feature/Auth/EmailVerificationTest.php
```
(`laravel/passkeys` stays in `vendor/` — Fortify requires it — but with the feature off it registers no routes and loads no migration.)

- [ ] **Step 4: Fortify config and provider**

In `config/fortify.php`: set `'limiters'` to
```php
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],
```
set `'features'` to
```php
    'features' => [
        Features::resetPasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
```
and delete the whole `'passkeys' => [ ... ],` block.

In `app/Providers/FortifyServiceProvider.php` delete the `use App\Actions\Fortify\CreateNewUser;` import, the lines `Fortify::createUsersUsing(CreateNewUser::class);`, `Fortify::verifyEmailView(...)`, `Fortify::registerView(...)`, and the whole `RateLimiter::for('passkeys', ...)` call. (Task 3 replaces this file completely.)

- [ ] **Step 5: User model, users migration and factory**

Replace `app/Models/User.php` with:
```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
```
In `database/migrations/0001_01_01_000000_create_users_table.php` delete the line `$table->timestamp('email_verified_at')->nullable();`.

In `database/factories/UserFactory.php` delete `'email_verified_at' => now(),` from `definition()` and delete the whole `unverified()` method.

- [ ] **Step 6: Routes**

Replace `routes/web.php` with:
```php
<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
```
Replace `routes/settings.php` with:
```php
<?php

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
    Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');

    Route::livewire('settings/security', Security::class)
        ->middleware(['password.confirm'])
        ->name('security.edit');
});
```

- [ ] **Step 7: Profile component and view**

Replace `app/Livewire/Settings/Profile.php` with:
```php
<?php

namespace App\Livewire\Settings;

use App\Concerns\ProfileValidationRules;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Profile settings')]
class Profile extends Component
{
    use ProfileValidationRules;

    public string $name = '';

    public string $email = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $user->fill($this->validate($this->profileRules($user->id)))->save();

        Flux::toast(variant: 'success', text: __('Profile updated.'));
    }
}
```
Replace `resources/views/livewire/settings/profile.blade.php` with:
```blade
<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Profile settings') }}</flux:heading>

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your name and email address')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-settings.layout>
</section>
```

- [ ] **Step 8: Security component — remove passkeys**

In `app/Livewire/Settings/Security.php`:
- delete `use Laravel\Passkeys\Actions\DeletePasskey;`
- delete the properties `$canManagePasskeys`, `$passkeys` (with its docblock), `$showDeleteModal`, `$deletingPasskeyId`, `$deletingPasskeyName`
- in `mount()` delete:
```php
        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
```
- delete the methods `loadPasskeys()`, `confirmDelete()`, `deletePasskey()` and `closeDeleteModal()` (with their docblocks).

In `resources/views/livewire/settings/security.blade.php` delete the whole `@if ($canManagePasskeys) ... @endif` section and the `<flux:modal name="delete-passkey-modal" ...> ... </flux:modal>` block.

- [ ] **Step 9: Login and confirm-password views, assets**

In `resources/views/livewire/auth/login.blade.php` delete `<x-passkey-verify />`, the two Remember-me lines:
```blade
            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />
```
and the whole sign-up block:
```blade
        <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
        </div>
```
In `resources/views/livewire/auth/confirm-password.blade.php` delete the `<x-passkey-verify ... />` element (all its lines).

In `vite.config.js` remove `'resources/js/passkeys.js'` from the `input` array. In `package.json` remove the `"@laravel/passkeys"` dependency, then run `npm install && npm run build`.

- [ ] **Step 10: Fix the kit tests that covered removed features**

- `tests/Feature/Settings/SecurityTest.php`: in `setUp()` delete the `Features::passkeys([...]);` call; in `test_security_settings_page_can_be_rendered` delete `->assertSee('Passkeys')` and `->assertSee('No passkeys yet')`; in `test_security_settings_page_renders_without_two_factor_when_feature_is_disabled` delete the two `assertDontSee('Manage your passkeys...')` / `assertDontSee('Add a passkey...')` lines.
- `tests/Feature/Settings/ProfileUpdateTest.php`: delete `$this->assertNull($user->email_verified_at);` and the tests `test_email_verification_status_is_unchanged_when_email_address_is_unchanged`, `test_user_can_delete_their_account` and `test_correct_password_must_be_provided_to_delete_account`.
- `tests/Feature/Auth/PasswordResetTest.php`: in the reset test replace the new password `'password'` (both `password` and `password_confirmation`) with `'new-password-123'` (Task 3 enforces 12 characters).

- [ ] **Step 11: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test
```
Expected: `RemovedFeaturesTest` passes (4 tests) and the full suite passes.

- [ ] **Step 12: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Remove registration, email verification, passkeys and account deletion

Admins create users and users are never deleted (spec 8.6); TOTP is the
only second factor.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 3: Login and session hardening

**Spec:** §8.6 (active users only, no "Remember me", throttling, password resets, 12-character passwords, 30-minute idle timeout, deactivation kills sessions)

**Files:**
- Create: `app/Http/Middleware/EnsureUserIsActive.php`, `tests/Feature/Auth/LoginSecurityTest.php`
- Modify: `database/migrations/0001_01_01_000000_create_users_table.php`, `app/Models/User.php`, `database/factories/UserFactory.php`, `app/Providers/FortifyServiceProvider.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/session.php`, `.env.example`, `app/Livewire/Settings/Security.php`

**Interfaces:**
- Consumes: Task 2 `User`
- Produces: `users.active` (bool, default true); `User::logoutEverywhere(?string $exceptSessionId = null): void`; `UserFactory::inactive()`; `UserFactory::withTwoFactor()` (kit); `App\Http\Middleware\EnsureUserIsActive` appended to the `web` group

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Auth/LoginSecurityTest.php`:
```php
<?php

use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

function attemptLogin(string $email, string $password = 'password', string $ip = '127.0.0.1', array $extra = [])
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post(route('login.store'), ['email' => $email, 'password' => $password, ...$extra]);
}

test('inactive users cannot log in', function () {
    $user = User::factory()->inactive()->create();

    attemptLogin($user->email)->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user deactivated mid-session is logged out on the next request', function () {
    $user = User::factory()->create();
    attemptLogin($user->email)->assertRedirect(route('dashboard', absolute: false));

    User::whereKey($user->id)->update(['active' => false]);
    auth()->forgetGuards(); // a real next request builds a fresh guard

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('login never sets a remember cookie, even when remember=on is posted', function () {
    $user = User::factory()->create();

    $response = attemptLogin($user->email, extra: ['remember' => 'on']);

    $this->assertAuthenticated();
    expect(collect($response->headers->getCookies())->map->getName()
        ->filter(fn (string $name) => str_starts_with($name, 'remember_')))->toBeEmpty();
});

test('the 2FA challenge never remembers either', function () {
    $user = User::factory()->withTwoFactor()->create();

    attemptLogin($user->email, extra: ['remember' => 'on'])->assertRedirect(route('two-factor.login'));

    expect(session('login.remember'))->toBeFalse();
});

test('login is limited to 5 attempts per minute per email and IP', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        attemptLogin($user->email, 'wrong-password')->assertSessionHasErrors('email');
    }

    attemptLogin($user->email)->assertStatus(429);
    attemptLogin($user->email, ip: '10.0.0.9')->assertRedirect(route('dashboard', absolute: false));
});

test('20 failures per hour per email block that email from any IP', function () {
    $user = User::factory()->create();

    foreach (range(1, 20) as $i) {
        attemptLogin($user->email, 'wrong-password', ip: "10.0.1.$i")->assertSessionHasErrors('email');
    }

    attemptLogin($user->email, ip: '10.0.2.1')->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->travel(61)->minutes();

    attemptLogin($user->email, ip: '10.0.2.1')->assertRedirect(route('dashboard', absolute: false));
});

test('deactivation deletes live database sessions and rotates remember_token', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    attemptLogin($user->email)->assertRedirect(route('dashboard', absolute: false));
    $cookie = [config('session.cookie') => session()->getId()];

    // Simulate a brand-new request: fresh session store and guard, only the cookie survives.
    $fresh = function () use ($cookie) {
        app('session')->forgetDrivers();
        auth()->forgetGuards();

        return $this->withCookies($cookie)->get(route('dashboard'));
    };

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1);
    $fresh()->assertOk();

    $token = $user->fresh()->remember_token;
    $user->forceFill(['active' => false])->save();
    $user->logoutEverywhere();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->remember_token)->not->toBe($token);
    $fresh()->assertRedirect(route('login'));
});

test('a password reset deletes sessions and rotates remember_token', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'abc', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $token = $user->remember_token;

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'twelve-chars-ok',
        'password_confirmation' => 'twelve-chars-ok',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe($token);
});

test('inactive users get no reset link and cannot reset', function () {
    Notification::fake();
    $user = User::factory()->inactive()->create();

    $this->post(route('password.email'), ['email' => $user->email])->assertSessionHas('status');
    Notification::assertNothingSent();

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'twelve-chars-ok',
        'password_confirmation' => 'twelve-chars-ok',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('a password change logs out the user\'s other sessions', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'a-new-password-1')
        ->set('password_confirmation', 'a-new-password-1')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(DB::table('sessions')->where('id', 'other-device')->exists())->toBeFalse();
});

test('passwords must be at least 12 characters', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'elevenchars')
        ->set('password_confirmation', 'elevenchars')
        ->call('updatePassword')
        ->assertHasErrors(['password' => 'The password field must be at least 12 characters.']);
});

test('sessions idle out after 30 minutes and use secure cookies', function () {
    expect(config('session.lifetime'))->toBe(30)
        ->and(config('session.secure'))->toBeTrue()
        ->and(config('session.expire_on_close'))->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Auth/LoginSecurityTest.php`
Expected: FAIL — `Call to undefined method ...UserFactory::inactive()`, remember cookie set, no throttle on 20 failures, lifetime 120.

- [ ] **Step 3: `active` column, User and factory**

In `database/migrations/0001_01_01_000000_create_users_table.php` add after `$table->string('password');`:
```php
            $table->boolean('active')->default(true);
```
Replace `app/Models/User.php` with:
```php
<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $active
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = ['active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    /** Kill every session of this user (database driver) and invalidate any remember cookie (spec §8.6). */
    public function logoutEverywhere(?string $exceptSessionId = null): void
    {
        DB::table(config('session.table'))
            ->where('user_id', $this->getKey())
            ->when($exceptSessionId, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        $this->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /** Inactive users silently get no reset link (the response still says "link sent"). */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if ($this->active) {
            parent::sendPasswordResetNotification($token);
        }
    }

    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
```
In `database/factories/UserFactory.php` add:
```php
    /**
     * Indicate that the user is deactivated.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
```

- [ ] **Step 4: Fortify: active users only, no remember-me, throttling**

Replace `app/Providers/FortifyServiceProvider.php` with:
```php
<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
        $this->configureAuthentication();
    }

    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('livewire.auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('livewire.auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('livewire.auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('livewire.auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('livewire.auth.forgot-password'));
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // 5 attempts per minute per email + IP (every POST /login counts).
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }

    /**
     * Active users only, no "remember me", and 20 failures per hour per email from any IP (spec §8.6).
     * Fortify calls this up to twice per successful login, so it must stay side-effect free on success.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            // Fortify reads $request->boolean('remember') after this callback, for both
            // guard->login() and the 2FA challenge's session('login.remember').
            $request->merge(['remember' => false]);

            $email = Str::lower((string) $request->input(Fortify::username()));
            $failuresKey = 'login-failures:'.$email;

            if (RateLimiter::tooManyAttempts($failuresKey, 20)) {
                $seconds = RateLimiter::availableIn($failuresKey);

                throw ValidationException::withMessages([
                    Fortify::username() => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
                ]);
            }

            // Same 200 ms timebox Fortify uses, so unknown emails aren't faster than wrong passwords.
            $user = (new Timebox)->call(function () use ($request, $email) {
                $user = User::where('email', $email)->first();

                return $user?->active && Hash::check((string) $request->input('password'), $user->password)
                    ? $user
                    : null;
            }, 200_000);

            if (! $user) {
                RateLimiter::hit($failuresKey, 3600);
            }

            // ponytail: replacing Fortify's check drops its rehash-on-login; add rehashPasswordIfRequired() if the hash cost changes.
            return $user;
        });
    }
}
```

- [ ] **Step 5: Password reset refuses inactive users and kills sessions**

Replace `app/Actions/Fortify/ResetUserPassword.php` with:
```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Called by Fortify's NewPasswordController after the token is verified.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        if (! $user->active) {
            throw ValidationException::withMessages(['email' => __('passwords.user')]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        $user->logoutEverywhere();
    }
}
```

- [ ] **Step 6: Inactive-user middleware, password rule, session defaults**

Create `app/Http/Middleware/EnsureUserIsActive.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => __('This account has been deactivated.')]);
        }

        return $next($request);
    }
}
```
In `bootstrap/app.php` replace the `withMiddleware` closure body (`//`) with:
```php
        // Every web request, including Livewire's update endpoint and Fortify's routes.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
        ]);
```
In `app/Providers/AppServiceProvider.php` replace the whole `Password::defaults(...)` statement with:
```php
        // Spec §8.6: at least 12 characters, in every environment (tests included).
        Password::defaults(fn (): Password => Password::min(12));
```
In `config/session.php` change the two defaults:
```php
    'lifetime' => (int) env('SESSION_LIFETIME', 30),
```
```php
    'secure' => env('SESSION_SECURE_COOKIE', true),
```
In `.env.example` set `SESSION_LIFETIME=30` and add `SESSION_SECURE_COOKIE=true`. In `.env` set `SESSION_LIFETIME=30` (leave `SESSION_SECURE_COOKIE=false` for local http).

- [ ] **Step 7: A password change kills other sessions**

In `app/Livewire/Settings/Security.php`, in `updatePassword()`, directly after the `Auth::user()->update([...]);` statement add:
```php
        // Spec §8.6: a password change kills the user's other sessions and rotates remember_token.
        Auth::user()->logoutEverywhere(exceptSessionId: session()->getId());
```

- [ ] **Step 8: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Auth/LoginSecurityTest.php
"$PHP" artisan test
```
Expected: 12 new tests pass; the full suite passes.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Harden login, password resets and sessions

Inactive users are refused at login and on every request; remember-me is
disabled server-side; login is limited to 5 attempts per minute per email
and IP plus 20 failures per hour per email; passwords need 12 characters;
deactivation, resets and password changes kill sessions; sessions idle
out after 30 minutes.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 4: Roles and permissions

**Spec:** §8.1 (roles, the 25 permissions, "finance `*.manage`", Vendor Support), §8.2 (`buildings.view-all` seeding)

**Files:**
- Create: `app/Enums/PermissionName.php`, `app/Enums/RoleName.php`, `database/seeders/RolesAndPermissionsSeeder.php`, `database/migrations/2026_09_28_000100_create_permission_tables.php` (published, renamed), `config/permission.php` (published), `tests/Feature/Auth/RolesAndPermissionsTest.php`
- Modify: `composer.json`, `app/Models/User.php`

**Interfaces:**
- Consumes: Task 3 `User`
- Produces: `PermissionName` (25 cases; `financeManage()`, `twoFactorRequired()`, `sensitiveGrants()`), `RoleName` (6 cases; `label()`), `RolesAndPermissionsSeeder::matrix(): array<string, list<PermissionName>>`, `User` uses `HasRoles`, `User::requiresTwoFactor(): bool`, `User::isVendorSupport(): bool`

- [ ] **Step 1: Install spatie/laravel-permission**

```bash
$COMPOSER require spatie/laravel-permission:^8
"$PHP" artisan vendor:publish --tag=permission-config --tag=permission-migrations
mv database/migrations/*_create_permission_tables.php database/migrations/2026_09_28_000100_create_permission_tables.php
```
Keep `config/permission.php` defaults (teams off, wildcard off, events off). Permission changes are audited explicitly by the Actions (Tasks 10, 12), not by package events.

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Auth/RolesAndPermissionsTest.php`:
```php
<?php

use App\Enums\PermissionName as P;
use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function roleHas(RoleName $role, P $permission): bool
{
    return Role::findByName($role->value)->hasPermissionTo($permission->value);
}

test('exactly the 25 spec permissions and 6 roles exist', function () {
    expect(Permission::pluck('name')->sort()->values()->all())->toBe(collect([
        'users.manage', 'roles.manage', 'settings.manage', 'templates.manage', 'audit.view',
        'buildings.view', 'buildings.manage', 'buildings.view-all', 'owners.view', 'owners.manage',
        'owners.bank.manage', 'customers.view', 'customers.manage', 'agreements.view', 'agreements.manage',
        'finance.view', 'invoices.manage', 'payments.manage', 'cheques.manage', 'disbursements.manage',
        'expenses.manage', 'reports.operational', 'reports.financial', 'approvals.decide', 'import.run',
    ])->sort()->values()->all())
        ->and(Role::pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'finance', 'leasing', 'management', 'property-manager', 'vendor-support']);
});

test('seeding twice changes nothing', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(Permission::count())->toBe(25)->and(Role::count())->toBe(6);
});

test('Admin cannot post money or approve', function () {
    foreach ([...P::financeManage(), P::ApprovalsDecide] as $permission) {
        expect(roleHas(RoleName::Admin, $permission))->toBeFalse();
    }
});

test('Vendor Support lacks approvals, bank details and finance posting, and is the only role with import', function () {
    foreach ([...P::financeManage(), P::ApprovalsDecide, P::OwnersBankManage] as $permission) {
        expect(roleHas(RoleName::VendorSupport, $permission))->toBeFalse();
    }

    foreach (RoleName::cases() as $role) {
        expect(roleHas($role, P::ImportRun))->toBe($role === RoleName::VendorSupport);
    }
});

test('buildings.view-all goes to every role except Leasing', function () {
    foreach ([RoleName::Admin, RoleName::Management, RoleName::Finance, RoleName::PropertyManager, RoleName::VendorSupport] as $role) {
        expect(roleHas($role, P::BuildingsViewAll))->toBeTrue();
    }

    expect(roleHas(RoleName::Leasing, P::BuildingsViewAll))->toBeFalse();
});

test('only Management decides approvals', function () {
    foreach (RoleName::cases() as $role) {
        expect(roleHas($role, P::ApprovalsDecide))->toBe($role === RoleName::Management);
    }
});

test('users with a sensitive permission require two-factor authentication', function () {
    $finance = User::factory()->create()->assignRole(RoleName::Finance);
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    expect($finance->requiresTwoFactor())->toBeTrue()
        ->and($leasing->requiresTwoFactor())->toBeFalse()
        ->and($finance->can(P::InvoicesManage))->toBeTrue()
        ->and($leasing->can(P::InvoicesManage))->toBeFalse();
});

test('isVendorSupport reflects the role', function () {
    expect(User::factory()->create()->assignRole(RoleName::VendorSupport)->isVendorSupport())->toBeTrue()
        ->and(User::factory()->create()->assignRole(RoleName::Admin)->isVendorSupport())->toBeFalse();
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Auth/RolesAndPermissionsTest.php`
Expected: FAIL — `Class "App\Enums\PermissionName" not found`.

- [ ] **Step 4: The enums**

Create `app/Enums/PermissionName.php`:
```php
<?php

namespace App\Enums;

/** The 25 permissions of spec §8.1. The values are the names stored by spatie/laravel-permission. */
enum PermissionName: string
{
    case UsersManage = 'users.manage';
    case RolesManage = 'roles.manage';
    case SettingsManage = 'settings.manage';
    case TemplatesManage = 'templates.manage';
    case AuditView = 'audit.view';
    case BuildingsView = 'buildings.view';
    case BuildingsManage = 'buildings.manage';
    case BuildingsViewAll = 'buildings.view-all';
    case OwnersView = 'owners.view';
    case OwnersManage = 'owners.manage';
    case OwnersBankManage = 'owners.bank.manage';
    case CustomersView = 'customers.view';
    case CustomersManage = 'customers.manage';
    case AgreementsView = 'agreements.view';
    case AgreementsManage = 'agreements.manage';
    case FinanceView = 'finance.view';
    case InvoicesManage = 'invoices.manage';
    case PaymentsManage = 'payments.manage';
    case ChequesManage = 'cheques.manage';
    case DisbursementsManage = 'disbursements.manage';
    case ExpensesManage = 'expenses.manage';
    case ReportsOperational = 'reports.operational';
    case ReportsFinancial = 'reports.financial';
    case ApprovalsDecide = 'approvals.decide';
    case ImportRun = 'import.run';

    /** "Finance *.manage" (spec §8.1). @return list<self> */
    public static function financeManage(): array
    {
        return [self::InvoicesManage, self::PaymentsManage, self::ChequesManage, self::DisbursementsManage, self::ExpensesManage];
    }

    /** Holders must confirm 2FA and cannot disable it (spec §8.6). @return list<self> */
    public static function twoFactorRequired(): array
    {
        return [
            self::UsersManage, self::RolesManage, self::SettingsManage, self::AuditView, self::FinanceView,
            ...self::financeManage(), self::ApprovalsDecide,
        ];
    }

    /** Granting any of these notifies every other approver (spec §8.1). @return list<self> */
    public static function sensitiveGrants(): array
    {
        return [self::ApprovalsDecide, ...self::financeManage()];
    }
}
```
Create `app/Enums/RoleName.php`:
```php
<?php

namespace App\Enums;

enum RoleName: string
{
    case Admin = 'admin';
    case Management = 'management';
    case Finance = 'finance';
    case PropertyManager = 'property-manager';
    case Leasing = 'leasing';
    case VendorSupport = 'vendor-support';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Management => 'Management',
            self::Finance => 'Finance',
            self::PropertyManager => 'Property manager',
            self::Leasing => 'Leasing',
            self::VendorSupport => 'Vendor support',
        };
    }
}
```

- [ ] **Step 5: The seeder**

Create `database/seeders/RolesAndPermissionsSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Enums\PermissionName as P;
use App\Enums\RoleName;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Spec §8.1 defaults. syncPermissions resets each role, so run this ONLY from rms:install and tests —
 * never on deploy, or it would undo Admin's audited role edits.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (P::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (self::matrix() as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions(array_map(fn (P $p) => $p->value, $permissions));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string, list<P>> */
    public static function matrix(): array
    {
        $view = [P::BuildingsView, P::OwnersView, P::CustomersView, P::AgreementsView, P::FinanceView];
        $reports = [P::ReportsOperational, P::ReportsFinancial];

        return [
            RoleName::Admin->value => [
                P::UsersManage, P::RolesManage, P::SettingsManage, P::TemplatesManage, P::AuditView,
                P::BuildingsView, P::BuildingsManage, P::BuildingsViewAll,
                P::OwnersView, P::OwnersManage, P::OwnersBankManage,
                P::CustomersView, P::CustomersManage,
                P::AgreementsView, P::AgreementsManage,
                P::FinanceView, // invoices, payments, payments out, expenses: view only
                ...$reports,
            ],
            RoleName::Management->value => [
                ...$view, P::BuildingsViewAll, P::ApprovalsDecide, P::AuditView, ...$reports,
            ],
            RoleName::Finance->value => [
                ...$view, P::BuildingsViewAll, P::OwnersManage, ...P::financeManage(), ...$reports,
            ],
            RoleName::PropertyManager->value => [
                P::BuildingsView, P::BuildingsManage, P::BuildingsViewAll, P::OwnersView,
                P::CustomersView, P::CustomersManage, P::AgreementsView, P::AgreementsManage,
                P::ExpensesManage, P::ReportsOperational,
            ],
            RoleName::Leasing->value => [ // assigned buildings only (spec §8.2)
                P::BuildingsView, P::CustomersView, P::CustomersManage,
                P::AgreementsView, P::AgreementsManage, P::ReportsOperational,
            ],
            RoleName::VendorSupport->value => array_values(array_filter(
                P::cases(),
                fn (P $p) => ! in_array($p, [P::ApprovalsDecide, P::OwnersBankManage, ...P::financeManage()], true),
            )), // includes import.run, which no other role gets
        ];
    }
}
```

- [ ] **Step 6: User gets roles**

In `app/Models/User.php` add the imports:
```php
use App\Enums\PermissionName;
use App\Enums\RoleName;
use Spatie\Permission\Traits\HasRoles;
```
change the trait line to:
```php
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;
```
and add these methods after `casts()`:
```php
    /** Spec §8.6: holders of a sensitive permission must use 2FA. Follows roles granted later. */
    public function requiresTwoFactor(): bool
    {
        return $this->hasAnyPermission(PermissionName::twoFactorRequired());
    }

    public function isVendorSupport(): bool
    {
        return $this->hasRole(RoleName::VendorSupport);
    }
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Auth/RolesAndPermissionsTest.php
"$PHP" artisan test
```
Expected: 8 new tests pass; full suite passes.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add roles and permissions from spec 8.1

Six roles and the 25 permissions, seeded only by install and tests.
Admin cannot post money or approve; Vendor Support cannot approve, post
money or edit bank details.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 5: Mandatory two-factor authentication

**Spec:** §8.6 (2FA mandatory for sensitive permissions, follows roles granted later, cannot be disabled)

**Files:**
- Create: `app/Http/Middleware/EnsureTwoFactorIsConfirmed.php`, `app/Actions/Fortify/DisableTwoFactorAuthentication.php`, `tests/Feature/Auth/MandatoryTwoFactorTest.php`
- Modify: `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Providers/FortifyServiceProvider.php`, `app/Livewire/Settings/Security.php`, `resources/views/livewire/settings/security.blade.php`

**Interfaces:**
- Consumes: `User::requiresTwoFactor()` (Task 4), `RolesAndPermissionsSeeder`, `RoleName`
- Produces: `EnsureTwoFactorIsConfirmed` (web group + Livewire persistent middleware); container binding of `Laravel\Fortify\Actions\DisableTwoFactorAuthentication` → `App\Actions\Fortify\DisableTwoFactorAuthentication` (refuses a self-disable by a sensitive user; still allows disabling someone else's 2FA)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/MandatoryTwoFactorTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Settings\Security;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

function financeUser(bool $withTwoFactor = false): User
{
    $factory = $withTwoFactor ? User::factory()->withTwoFactor() : User::factory();

    return $factory->create()->assignRole(RoleName::Finance);
}

test('sensitive users without confirmed 2FA are sent to 2FA setup', function () {
    $this->actingAs(financeUser());

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->get(route('profile.edit'))->assertRedirect(route('security.edit'));

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertSee('Your role requires two-factor authentication');
});

test('the requirement follows a role granted later', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->assignRole(RoleName::Finance);

    $this->get(route('dashboard'))->assertRedirect(route('security.edit'));
});

test('sensitive users with confirmed 2FA use the app normally', function () {
    $this->actingAs(financeUser(withTwoFactor: true))->get(route('dashboard'))->assertOk();
});

test('Livewire update requests from other pages are guarded too', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
    $snapshot = htmlspecialchars_decode(str($html)->betweenFirst('wire:snapshot="', '"'), ENT_QUOTES);

    $update = function () use ($snapshot) {
        app('livewire')->flushState(); // production gets this for free: one PHP request per update

        return $this->withHeader('X-Livewire', '1')->postJson(app('livewire')->getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => ['name' => 'Changed'], 'calls' => [['method' => 'updateProfileInformation', 'params' => []]]]],
        ]);
    };

    $update()->assertOk(); // control: the hand-built update request works

    $user->assignRole(RoleName::Management);

    $update()->assertRedirect(route('security.edit'));
});

test('sensitive users cannot disable confirmed 2FA', function () {
    $user = financeUser(withTwoFactor: true);
    $this->actingAs($user);

    Livewire::test(Security::class)
        ->assertDontSee('Disable 2FA')
        ->call('disable')
        ->assertForbidden();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))
        ->assertForbidden();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('another user\'s 2FA can still be reset', function () {
    $user = financeUser(withTwoFactor: true);
    $this->actingAs(User::factory()->create());

    app(DisableTwoFactorAuthentication::class)($user);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('users without sensitive permissions can still disable 2FA', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Leasing);
    $this->actingAs($user);

    Livewire::test(Security::class)->assertSee('Disable 2FA')->call('disable')->assertHasNoErrors();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('an abandoned, unconfirmed setup is still cleared', function () {
    $user = financeUser();
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => null])->save();
    $this->actingAs($user);

    Livewire::test(Security::class)->assertSet('twoFactorEnabled', false);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Auth/MandatoryTwoFactorTest.php`
Expected: FAIL — the dashboard returns 200 instead of redirecting, and `disable` succeeds.

- [ ] **Step 3: The middleware**

Create `app/Http/Middleware/EnsureTwoFactorIsConfirmed.php`:
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsConfirmed
{
    /**
     * Routes a user without confirmed 2FA still needs in order to set it up (or leave).
     * Livewire's update endpoint is exempt because Livewire re-runs this middleware
     * against the component's original page route (persistent middleware).
     */
    private const array EXEMPT_ROUTES = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'logout',
        '*livewire.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user
            && is_null($user->two_factor_confirmed_at)
            && ! $request->routeIs(...self::EXEMPT_ROUTES)
            && $user->requiresTwoFactor()) {
            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
```
In `bootstrap/app.php` extend the web append list to:
```php
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\EnsureTwoFactorIsConfirmed::class,
        ]);
```
In `app/Providers/AppServiceProvider.php` add `use App\Http\Middleware\EnsureTwoFactorIsConfirmed;` and `use Livewire\Livewire;`, and make `boot()`:
```php
    public function boot(): void
    {
        $this->configureDefaults();

        // Livewire update requests re-run this against the component's original page route.
        Livewire::addPersistentMiddleware([EnsureTwoFactorIsConfirmed::class]);
    }
```

- [ ] **Step 4: Refuse self-disable for sensitive users**

Create `app/Actions/Fortify/DisableTwoFactorAuthentication.php`:
```php
<?php

namespace App\Actions\Fortify;

use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    /**
     * Users holding a sensitive permission cannot switch off their own confirmed 2FA.
     * Abandoned (unconfirmed) setups can still be cleared, and an admin can reset someone else's.
     *
     * @param  mixed  $user
     */
    public function __invoke($user): void
    {
        abort_if(
            $user->is(auth()->user()) && $user->two_factor_confirmed_at !== null && $user->requiresTwoFactor(),
            403,
            __('Your role requires two-factor authentication.'),
        );

        parent::__invoke($user);
    }
}
```
In `app/Providers/FortifyServiceProvider.php` replace `register()` with:
```php
    public function register(): void
    {
        // Used by the Security component AND Fortify's DELETE /user/two-factor-authentication route.
        $this->app->bind(
            \Laravel\Fortify\Actions\DisableTwoFactorAuthentication::class,
            \App\Actions\Fortify\DisableTwoFactorAuthentication::class,
        );
    }
```

- [ ] **Step 5: Security screen**

In `app/Livewire/Settings/Security.php` add the property:
```php
    /** The user holds a sensitive permission: 2FA is mandatory and cannot be disabled. */
    #[Locked]
    public bool $mustUseTwoFactor = false;
```
and inside `mount()`, inside `if ($this->canManageTwoFactor) { ... }`, after the `$this->requiresConfirmation = ...` line:
```php
            $this->mustUseTwoFactor = auth()->user()->requiresTwoFactor();
```
In `resources/views/livewire/settings/security.blade.php` wrap the Disable button block:
```blade
                            @unless ($mustUseTwoFactor)
                                <div class="flex justify-start">
                                    <flux:button variant="danger" wire:click="disable">
                                        {{ __('Disable 2FA') }}
                                    </flux:button>
                                </div>
                            @endunless
```
and at the top of the `@else` (not-enabled) branch, as the first child of its `<div class="space-y-4">`, add:
```blade
                            @if ($mustUseTwoFactor)
                                <flux:callout variant="warning" icon="shield-exclamation" :heading="__('Your role requires two-factor authentication. Enable it to continue using the application.')" />
                            @endif
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Auth/MandatoryTwoFactorTest.php
"$PHP" artisan test
```
Expected: 8 new tests pass; full suite passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Make two-factor authentication mandatory for sensitive permissions

Users holding admin, finance or approval permissions are sent to 2FA
setup until confirmed, on page and Livewire requests alike, and cannot
disable it themselves.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 6: Audit log

**Spec:** §8.4 (what is logged, ip + user agent, no secrets, read-only, kept forever), §8.5 (activity_log append-only)

**Files:**
- Create: `app/Audit/Audit.php`, `app/Audit/LogActivityAction.php`, `app/Audit/AuthEventSubscriber.php`, `database/migrations/2026_09_28_000200_create_activity_log_table.php` (published, edited), `database/migrations/2026_09_28_000300_add_activity_log_immutability_triggers.php`, `config/activitylog.php` (published), `tests/Feature/Audit/ActivityLogTest.php`
- Modify: `composer.json`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `app/Livewire/Settings/Security.php`

**Interfaces:**
- Consumes: Task 5 state
- Produces: `App\Audit\Audit::log(string $event, ?Model $subject = null, array $old = [], array $new = [], array $properties = [], ?Model $causer = null): ?Activity`; auth events logged per contract; `User::AUDIT_SECRETS`; `activity_log` rows have `ip`, `user_agent`, `attribute_changes`; triggers `activity_log_no_update`, `activity_log_no_delete`

- [ ] **Step 1: Install spatie/laravel-activitylog**

```bash
$COMPOSER require spatie/laravel-activitylog:^5
"$PHP" artisan vendor:publish --tag=activitylog-config --tag=activitylog-migrations
mv database/migrations/*_create_activity_log_table.php database/migrations/2026_09_28_000200_create_activity_log_table.php
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Audit/ActivityLogTest.php`:
```php
<?php

use App\Audit\Audit;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

function events(): array
{
    return Activity::query()->orderBy('id')->pluck('event')->all();
}

test('a model change over HTTP records ip, user agent and old/new values', function () {
    $user = User::factory()->create(['name' => 'Before']);
    Route::middleware('web')->post('/_test/rename', fn () => tap($user)->update(['name' => 'After']) ? 'ok' : 'no');

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.1.2.3', 'HTTP_USER_AGENT' => 'PestUA/1.0'])
        ->post('/_test/rename')->assertOk();

    $row = Activity::query()->where('event', 'updated')->latest('id')->firstOrFail();
    expect($row->ip)->toBe('10.1.2.3')
        ->and($row->user_agent)->toBe('PestUA/1.0')
        ->and($row->attribute_changes->toArray())->toEqual(['old' => ['name' => 'Before'], 'attributes' => ['name' => 'After']]);
});

test('secrets never reach the audit log', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Security::class)
        ->set('current_password', 'password')
        ->set('password', 'a-new-password-1')
        ->set('password_confirmation', 'a-new-password-1')
        ->call('updatePassword');

    app(EnableTwoFactorAuthentication::class)($user->fresh());
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    app(DisableTwoFactorAuthentication::class)($user->fresh());
    $user->fresh()->logoutEverywhere();

    $raw = DB::table('activity_log')->get()->map(fn ($row) => json_encode($row))->implode("\n");
    foreach (User::AUDIT_SECRETS as $secret) {
        expect($raw)->not->toContain('"'.$secret.'"');
    }
});

test('login, failed login and logout are logged', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    $failed = Activity::query()->where('event', 'auth.login.failed')->firstOrFail();
    expect($failed->causer_id)->toBeNull()->and($failed->getProperty('email'))->toBe($user->email);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
    $this->post(route('logout'));

    expect(events())->toContain('auth.login', 'auth.logout');
    expect(Activity::query()->where('event', 'auth.login')->first()->causer_id)->toBe($user->id);
});

test('a password reset and a password change are logged', function () {
    $user = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'twelve-chars-ok',
        'password_confirmation' => 'twelve-chars-ok',
    ]);

    $this->actingAs($user->fresh());
    Livewire::test(Security::class)
        ->set('current_password', 'twelve-chars-ok')
        ->set('password', 'a-new-password-1')
        ->set('password_confirmation', 'a-new-password-1')
        ->call('updatePassword');

    expect(events())->toContain('auth.password.reset', 'auth.password.changed');
});

test('2FA enable, confirm, disable and a failed challenge are logged', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    app(EnableTwoFactorAuthentication::class)($user);
    $code = app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    app(ConfirmTwoFactorAuthentication::class)($user->fresh(), $code);
    app(DisableTwoFactorAuthentication::class)($user->fresh());

    auth()->logout();
    $withTwoFactor = User::factory()->withTwoFactor()->create();
    $this->post(route('login.store'), ['email' => $withTwoFactor->email, 'password' => 'password']);
    $this->post(route('two-factor.login.store'), ['code' => '000000']);

    expect(events())->toContain('auth.2fa.enabled', 'auth.2fa.confirmed', 'auth.2fa.disabled', 'auth.2fa.failed');
});

test('Audit::log stores old and new like model rows do', function () {
    $user = User::factory()->create();

    $row = Audit::log('user.roles.changed', $user, ['roles' => ['leasing']], ['roles' => ['finance', 'leasing']], ['note' => 'x']);

    expect($row->log_name)->toBe('audit')
        ->and($row->attribute_changes->toArray())->toEqual(['old' => ['roles' => ['leasing']], 'attributes' => ['roles' => ['finance', 'leasing']]])
        ->and($row->getProperty('note'))->toBe('x');
});

test('the audit log cannot be updated or deleted', function () {
    $row = Audit::log('test.event');

    expect(fn () => DB::table('activity_log')->where('id', $row->id)->update(['event' => 'x']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo)->toBe(['45000', 1644, 'activity_log is append-only']));
    expect(fn () => DB::table('activity_log')->where('id', $row->id)->delete())
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo)->toBe(['45000', 1644, 'activity_log is append-only']));
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `"$PHP" artisan migrate:fresh --database=migrator --force && "$PHP" artisan test tests/Feature/Audit/ActivityLogTest.php`
Expected: FAIL — `Class "App\Audit\Audit" not found` and no `ip` column.

- [ ] **Step 4: Migration with ip and user agent; append-only triggers**

Replace `database/migrations/2026_09_28_000200_create_activity_log_table.php` with:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();      // spec §8.4
            $table->text('user_agent')->nullable();    // spec §8.4
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
```
Create `database/migrations/2026_09_28_000300_add_activity_log_immutability_triggers.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // One statement per unprepared() call (spec §8.5).
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER activity_log_no_update BEFORE UPDATE ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only'");
        DB::unprepared("CREATE TRIGGER activity_log_no_delete BEFORE DELETE ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'activity_log is append-only'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS activity_log_no_delete');
    }
};
```

- [ ] **Step 5: Config and the custom log action**

In `config/activitylog.php`:
- replace `use Spatie\Activitylog\Actions\LogActivityAction;` with `use App\Audit\LogActivityAction;`
- set `'default_except_attributes' => ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'],`
- change the `'clean_after_days' => 365,` line to `'clean_after_days' => 365, // RMS: kept forever (spec §8.4) — never schedule activitylog:clean; the triggers block DELETE anyway`

Create `app/Audit/LogActivityAction.php`:
```php
<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Actions\LogActivityAction as BaseLogActivityAction;

class LogActivityAction extends BaseLogActivityAction
{
    protected function beforeActivityLogged(Model $activity): void
    {
        $request = request();

        // Only real HTTP requests carry a route; queue, scheduler and artisan get a fake 127.0.0.1/"Symfony" request.
        if ($request->route() !== null) {
            $activity->ip ??= $request->ip();
            $activity->user_agent ??= $request->userAgent();
        }

        parent::beforeActivityLogged($activity);
    }
}
```
Create `app/Audit/Audit.php`:
```php
<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Contracts\Activity;

/** Explicit audit entries for changes model events cannot see (spec §8.4). */
final class Audit
{
    /**
     * @param  array<string, mixed>  $old  e.g. ['roles' => ['leasing']]
     * @param  array<string, mixed>  $new  e.g. ['roles' => ['finance', 'leasing']]
     * @param  array<string, mixed>  $properties  extra context — never secrets
     */
    public static function log(
        string $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $properties = [],
        ?Model $causer = null,
    ): ?Activity {
        $changes = array_filter(['attributes' => $new, 'old' => $old]);

        return activity('audit')
            ->event($event)
            ->when($subject, fn ($log) => $log->performedOn($subject))
            ->when($causer, fn ($log) => $log->causedBy($causer))
            ->withChanges($changes)
            ->withProperties($properties)
            ->log($event);
    }
}
```

- [ ] **Step 6: Auth events**

Create `app/Audit/AuthEventSubscriber.php`:
```php
<?php

namespace App\Audit;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

class AuthEventSubscriber
{
    private const array USER_EVENTS = [
        Login::class => 'auth.login',
        Logout::class => 'auth.logout',
        PasswordReset::class => 'auth.password.reset',
        TwoFactorAuthenticationEnabled::class => 'auth.2fa.enabled',
        TwoFactorAuthenticationConfirmed::class => 'auth.2fa.confirmed',
        TwoFactorAuthenticationDisabled::class => 'auth.2fa.disabled',
        TwoFactorAuthenticationFailed::class => 'auth.2fa.failed',
        RecoveryCodesGenerated::class => 'auth.2fa.recovery_codes_generated',
    ];

    public function logUserEvent(object $event): void
    {
        $user = $event->user instanceof Model ? $event->user : null;

        Audit::log(self::USER_EVENTS[$event::class], $user, causer: $user);
    }

    public function logFailedLogin(Failed $event): void
    {
        // No causer: the attempt is not authenticated. Only the email is kept, never the password.
        activity('audit')->causedByAnonymous()->event('auth.login.failed')
            ->withProperties(['email' => $event->credentials['email'] ?? null])
            ->log('auth.login.failed');
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ...array_fill_keys(array_keys(self::USER_EVENTS), 'logUserEvent'),
            Failed::class => 'logFailedLogin',
        ];
    }
}
```
In `app/Providers/AppServiceProvider.php` add `use App\Audit\AuthEventSubscriber;` and `use Illuminate\Support\Facades\Event;`, and in `boot()` add:
```php
        Event::subscribe(AuthEventSubscriber::class);
```
In `app/Livewire/Settings/Security.php` add `use App\Audit\Audit;` and, in `updatePassword()` after the `logoutEverywhere(...)` line:
```php
        Audit::log('auth.password.changed', Auth::user(), causer: Auth::user());
```

- [ ] **Step 7: User model logging options**

In `app/Models/User.php` add the imports:
```php
use Spatie\Activitylog\Models\Concerns\HasActivity;
use Spatie\Activitylog\Support\LogOptions;
```
change the trait line to:
```php
    use HasActivity, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;
```
and add below the trait line:
```php
    /** Never written to the audit log (spec §8.4). #[Hidden] does NOT keep attributes out of it. */
    public const array AUDIT_SECRETS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logExcept(self::AUDIT_SECRETS)
            ->dontLogIfAttributesChangedOnly([...self::AUDIT_SECRETS, 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
```

- [ ] **Step 8: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Audit/ActivityLogTest.php
"$PHP" artisan test
```
Expected: 7 new tests pass; full suite passes. If the 2FA test fails on `PragmaRX\Google2FA\Google2FA`, check the class Fortify's provider uses with `grep -rn "Google2FA" vendor/laravel/fortify/src/TwoFactorAuthenticationProvider.php` and use that class.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the append-only audit log

Model changes, logins, failed logins, logouts, password resets and
changes, and 2FA events are recorded with IP and user agent. Secrets are
excluded, and MySQL triggers reject any UPDATE or DELETE on the log.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 7: Company settings

**Spec:** §3 (settings fields, install-level keys), §8.4 (settings changes audited)

**Files:**
- Create: `app/Enums/TaxCategory.php`, `app/Enums/ProrationBasis.php`, `database/migrations/2026_09_28_000400_create_company_settings_table.php`, `app/Models/CompanySetting.php`, `database/factories/CompanySettingFactory.php`, `app/Actions/Settings/UpdateCompanySettings.php`, `app/Livewire/Admin/CompanySettings.php`, `resources/views/livewire/admin/company-settings.blade.php`, `routes/admin.php`, `app/Console/Commands/SetInstallSetting.php`, `tests/Feature/Settings/CompanySettingsTest.php`, `tests/Feature/Settings/SetInstallSettingTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `Audit::log` (Task 6), `PermissionName`, `RolesAndPermissionsSeeder` (Task 4)
- Produces: `CompanySetting::current(): self`; `CompanySetting::factory()`; `UpdateCompanySettings::handle(User $actor, array $data, ?UploadedFile $logo = null): CompanySetting`; `routes/admin.php` group (prefix `admin`, name `admin.`, middleware `auth`); route `admin.settings`; command `rms:setting {key} {value}`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Settings/CompanySettingsTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Enums\TaxCategory;
use App\Livewire\Admin\CompanySettings;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
});

test('guests are redirected and users without settings.manage are forbidden', function () {
    $this->get(route('admin.settings'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('admin.settings'))->assertForbidden();
});

test('an admin sees and saves the settings', function () {
    $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->assertSee('Company settings');

    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.name_en', 'Example Properties W.L.L.')
        ->set('form.name_ar', 'شركة مثال للعقارات')
        ->set('form.vat_registered', true)
        ->set('form.trn', '200000000000003')
        ->set('form.vat_rate', '10.00')
        ->set('form.commercial_tax_category', 'standard')
        ->set('form.default_grace_days', 7)
        ->call('save')
        ->assertHasNoErrors();

    $settings = CompanySetting::current();
    expect($settings->name_en)->toBe('Example Properties W.L.L.')
        ->and($settings->vat_registered)->toBeTrue()
        ->and($settings->commercial_tax_category)->toBe(TaxCategory::Standard)
        ->and($settings->default_grace_days)->toBe(7);
});

test('invalid values are rejected', function () {
    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.name_en', '')
        ->set('form.vat_registered', true)
        ->set('form.trn', '')
        ->set('form.vat_rate', '10.555')
        ->set('form.currency_code', 'bh')
        ->set('form.date_format', 'm/d/Y')
        ->set('form.default_grace_days', 61)
        ->set('form.residential_tax_category', 'nonsense')
        ->call('save')
        ->assertHasErrors(['form.name_en', 'form.trn', 'form.vat_rate', 'form.currency_code', 'form.date_format', 'form.default_grace_days', 'form.residential_tax_category']);
});

test('the logo is stored privately', function () {
    Storage::fake('local');

    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 80))
        ->call('save')
        ->assertHasNoErrors();

    expect(CompanySetting::current()->logo_path)->toBe('settings/logo.png');
    Storage::disk('local')->assertExists('settings/logo.png');
});

test('the screen cannot change install-level settings', function () {
    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.require_different_approver', false)
        ->set('form.go_live_at', '2026-01-01')
        ->call('save');

    $settings = CompanySetting::current();
    expect($settings->require_different_approver)->toBeTrue()->and($settings->go_live_at)->toBeNull();
});

test('only one settings row can exist', function () {
    expect(fn () => DB::table('company_settings')->insert(['id' => 2, 'name_en' => 'Second']))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(3819));
});

test('changes are audited', function () {
    Livewire::actingAs($this->admin)->test(CompanySettings::class)
        ->set('form.name_en', 'Renamed Co')
        ->call('save');

    $row = Activity::query()->where('subject_type', (new CompanySetting)->getMorphClass())->where('event', 'updated')->latest('id')->firstOrFail();
    expect($row->causer_id)->toBe($this->admin->id)
        ->and($row->attribute_changes['attributes']['name_en'])->toBe('Renamed Co');
});
```
Create `tests/Feature/Settings/SetInstallSettingTest.php`:
```php
<?php

use App\Models\CompanySetting;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => CompanySetting::factory()->create());

test('it switches the different-approver rule and audits it', function () {
    $this->artisan('rms:setting', ['key' => 'require_different_approver', 'value' => 'false'])->assertSuccessful();

    expect(CompanySetting::current()->require_different_approver)->toBeFalse();

    $row = Activity::query()->where('event', 'settings.install_level.changed')->firstOrFail();
    expect($row->attribute_changes->toArray())->toEqual([
        'old' => ['require_different_approver' => true],
        'attributes' => ['require_different_approver' => false],
    ]);
});

test('it sets and clears the go-live date', function () {
    $this->artisan('rms:setting', ['key' => 'go_live_at', 'value' => '2026-12-01'])->assertSuccessful();
    expect(CompanySetting::current()->go_live_at->toDateString())->toBe('2026-12-01');

    $this->artisan('rms:setting', ['key' => 'go_live_at', 'value' => 'null'])->assertSuccessful();
    expect(CompanySetting::current()->go_live_at)->toBeNull();
});

test('it rejects unknown keys and bad values', function () {
    $this->artisan('rms:setting', ['key' => 'vat_rate', 'value' => '5'])->assertFailed();
    $this->artisan('rms:setting', ['key' => 'require_different_approver', 'value' => 'maybe'])->assertFailed();
    $this->artisan('rms:setting', ['key' => 'go_live_at', 'value' => 'soon'])->assertFailed();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Settings`
Expected: FAIL — `Class "App\Models\CompanySetting" not found`.

- [ ] **Step 3: Enums and migration**

Create `app/Enums/TaxCategory.php`:
```php
<?php

namespace App\Enums;

enum TaxCategory: string
{
    case Standard = 'standard';
    case ZeroRated = 'zero_rated';
    case Exempt = 'exempt';
    case OutOfScope = 'out_of_scope';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard rated',
            self::ZeroRated => 'Zero rated',
            self::Exempt => 'Exempt',
            self::OutOfScope => 'Out of scope',
        };
    }
}
```
Create `app/Enums/ProrationBasis.php`:
```php
<?php

namespace App\Enums;

enum ProrationBasis: string
{
    case Actual365 = 'actual_365';
    case Days30 = 'days_30';

    public function label(): string
    {
        return match ($this) {
            self::Actual365 => 'Actual days / 365',
            self::Days30 => '30-day month',
        };
    }
}
```
Create `database/migrations/2026_09_28_000400_create_company_settings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();
            $table->string('cr_number', 30)->nullable();
            $table->text('address_en')->nullable();
            $table->text('address_ar')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_path')->nullable();
            $table->boolean('vat_registered')->default(false);
            $table->string('trn', 20)->nullable();
            $table->decimal('vat_rate', 5, 2)->default(10.00);
            $table->string('residential_tax_category', 20)->default('exempt');
            $table->string('commercial_tax_category', 20)->default('standard');
            $table->char('currency_code', 3)->default('BHD');
            $table->string('date_format', 10)->default('d/m/Y');
            $table->unsignedSmallInteger('default_grace_days')->default(5);
            $table->unsignedSmallInteger('invoice_lead_days')->default(7);
            $table->string('proration_basis', 20)->default('actual_365');
            $table->boolean('require_different_approver')->default(true);
            $table->timestamp('go_live_at')->nullable();
            $table->timestamps();
        });

        // Blueprint has no check() in Laravel 13: one ALTER per constraint set.
        DB::statement(<<<'SQL'
            ALTER TABLE company_settings
                ADD CONSTRAINT company_settings_single_row CHECK (id = 1),
                ADD CONSTRAINT company_settings_residential_tax_chk CHECK (residential_tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT company_settings_commercial_tax_chk CHECK (commercial_tax_category IN ('standard', 'zero_rated', 'exempt', 'out_of_scope')),
                ADD CONSTRAINT company_settings_proration_chk CHECK (proration_basis IN ('actual_365', 'days_30'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
```

- [ ] **Step 4: Model and factory**

Create `app/Models/CompanySetting.php`:
```php
<?php

namespace App\Models;

use App\Enums\ProrationBasis;
use App\Enums\TaxCategory;
use Database\Factories\CompanySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * The single settings row of this install (spec §3).
 *
 * @property string $name_en
 * @property string|null $name_ar
 * @property string|null $logo_path
 * @property bool $vat_registered
 * @property string|null $trn
 * @property string $vat_rate
 * @property TaxCategory $residential_tax_category
 * @property TaxCategory $commercial_tax_category
 * @property string $currency_code
 * @property string $date_format
 * @property int $default_grace_days
 * @property int $invoice_lead_days
 * @property ProrationBasis $proration_basis
 * @property bool $require_different_approver
 * @property Carbon|null $go_live_at
 */
class CompanySetting extends Model
{
    /** @use HasFactory<CompanySettingFactory> */
    use HasFactory, LogsActivity;

    /** Screen-editable fields. require_different_approver and go_live_at are set only by rms:setting. */
    public const array EDITABLE = [
        'name_en', 'name_ar', 'cr_number', 'address_en', 'address_ar', 'phone', 'email', 'website',
        'vat_registered', 'trn', 'vat_rate', 'residential_tax_category', 'commercial_tax_category',
        'currency_code', 'date_format', 'default_grace_days', 'invoice_lead_days', 'proration_basis',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vat_registered' => 'boolean',
            'vat_rate' => 'decimal:2',
            'residential_tax_category' => TaxCategory::class,
            'commercial_tax_category' => TaxCategory::class,
            'default_grace_days' => 'integer',
            'invoice_lead_days' => 'integer',
            'proration_basis' => ProrationBasis::class,
            'require_different_approver' => 'boolean',
            'go_live_at' => 'datetime',
        ];
    }

    /** Memoised per request; the memo is cleared whenever the row is saved (booted() below). */
    public static function current(): self
    {
        return once(fn () => static::query()->findOrFail(1));
    }

    protected static function booted(): void
    {
        static::saved(fn () => \Illuminate\Support\Once::flush());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }
}
```
Create `database/factories/CompanySettingFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanySetting>
 */
class CompanySettingFactory extends Factory
{
    protected $model = CompanySetting::class;

    public function definition(): array
    {
        return [
            'id' => 1,
            'name_en' => 'Demo Properties W.L.L.',
            'name_ar' => 'شركة ديمو للعقارات ذ.م.م',
            'email' => 'office@demo.test',
        ];
    }
}
```

- [ ] **Step 5: The Action**

Create `app/Actions/Settings/UpdateCompanySettings.php`:
```php
<?php

namespace App\Actions\Settings;

use App\Enums\PermissionName;
use App\Enums\ProrationBasis;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateCompanySettings
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'name_en' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'cr_number' => ['nullable', 'string', 'max:30'],
            'address_en' => ['nullable', 'string', 'max:500'],
            'address_ar' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'vat_registered' => ['boolean'],
            'trn' => ['nullable', 'required_if:vat_registered,true', 'string', 'max:20'],
            'vat_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'residential_tax_category' => ['required', Rule::enum(TaxCategory::class)],
            'commercial_tax_category' => ['required', Rule::enum(TaxCategory::class)],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'date_format' => ['required', Rule::in(['d/m/Y', 'Y-m-d', 'd-m-Y'])],
            'default_grace_days' => ['required', 'integer', 'between:0,60'],
            'invoice_lead_days' => ['required', 'integer', 'between:0,60'],
            'proration_basis' => ['required', Rule::enum(ProrationBasis::class)],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, array $data, ?UploadedFile $logo = null): CompanySetting
    {
        if (! $actor->can(PermissionName::SettingsManage)) {
            throw new AuthorizationException;
        }

        // Only screen-editable keys ever reach the model (install-level keys are dropped here).
        $validated = Validator::make(Arr::only($data, CompanySetting::EDITABLE), self::rules())->validate();

        if ($logo) {
            Validator::make(['logo' => $logo], ['logo' => ['image', 'mimes:png,jpg,jpeg', 'max:2048']])->validate();
        }

        return DB::transaction(function () use ($validated, $logo) {
            $settings = CompanySetting::query()->lockForUpdate()->findOrFail(1);

            if ($logo) {
                $validated['logo_path'] = $logo->storeAs('settings', 'logo.'.$logo->extension(), 'local');
            }

            $settings->fill($validated)->save();

            return $settings;
        });
    }
}
```

- [ ] **Step 6: Screen and route**

Create `app/Livewire/Admin/CompanySettings.php`:
```php
<?php

namespace App\Livewire\Admin;

use App\Actions\Settings\UpdateCompanySettings;
use App\Enums\ProrationBasis;
use App\Enums\TaxCategory;
use App\Models\CompanySetting;
use Flux\Flux;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Company settings')]
class CompanySettings extends Component
{
    use WithFileUploads;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var UploadedFile|null */
    public $logo = null;

    public function mount(): void
    {
        $settings = CompanySetting::current();
        $this->form = Arr::only($settings->attributesToArray(), CompanySetting::EDITABLE);
    }

    public function save(UpdateCompanySettings $update): void
    {
        try {
            $update->handle(auth()->user(), $this->form, $this->logo);
        } catch (ValidationException $e) {
            // Prefix field names so errors appear next to wire:model="form.x".
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn ($messages, $key) => [$key === 'logo' ? 'logo' : "form.$key" => $messages])->all()
            );
        }

        $this->reset('logo');
        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    public function render()
    {
        return view('livewire.admin.company-settings', [
            'taxCategories' => TaxCategory::cases(),
            'prorationBases' => ProrationBasis::cases(),
        ]);
    }
}
```
Create `resources/views/livewire/admin/company-settings.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-8">
    <flux:heading size="xl" level="1">{{ __('Company settings') }}</flux:heading>

    <form wire:submit="save" class="space-y-8">
        <flux:fieldset>
            <flux:legend>{{ __('Profile') }}</flux:legend>
            <div class="space-y-4">
                <flux:input wire:model="form.name_en" :label="__('Name (English)')" required />
                <flux:input wire:model="form.name_ar" :label="__('Name (Arabic)')" dir="rtl" />
                <flux:input wire:model="form.cr_number" :label="__('CR number')" />
                <flux:textarea wire:model="form.address_en" :label="__('Address (English)')" rows="2" />
                <flux:textarea wire:model="form.address_ar" :label="__('Address (Arabic)')" rows="2" dir="rtl" />
                <flux:input wire:model="form.phone" :label="__('Phone')" type="tel" />
                <flux:input wire:model="form.email" :label="__('Email')" type="email" />
                <flux:input wire:model="form.website" :label="__('Website')" type="url" />
                <flux:field>
                    <flux:label>{{ __('Logo (PNG or JPG, max 2 MB)') }}</flux:label>
                    <input type="file" wire:model="logo" accept="image/png,image/jpeg" class="block w-full text-sm" />
                    <flux:error name="logo" />
                </flux:field>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Tax') }}</flux:legend>
            <div class="space-y-4">
                <flux:checkbox wire:model="form.vat_registered" :label="__('VAT registered')" />
                <flux:input wire:model="form.trn" :label="__('TRN')" />
                <flux:input wire:model="form.vat_rate" :label="__('VAT rate (%)')" inputmode="decimal" />
                <flux:select wire:model="form.residential_tax_category" :label="__('Default tax for residential units')">
                    @foreach ($taxCategories as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="form.commercial_tax_category" :label="__('Default tax for commercial units')">
                    @foreach ($taxCategories as $category)
                        <option value="{{ $category->value }}">{{ $category->label() }}</option>
                    @endforeach
                </flux:select>
            </div>
        </flux:fieldset>

        <flux:fieldset>
            <flux:legend>{{ __('Locale and billing') }}</flux:legend>
            <div class="space-y-4">
                <flux:input wire:model="form.currency_code" :label="__('Currency code')" maxlength="3" />
                <flux:select wire:model="form.date_format" :label="__('Date format')">
                    <option value="d/m/Y">DD/MM/YYYY</option>
                    <option value="d-m-Y">DD-MM-YYYY</option>
                    <option value="Y-m-d">YYYY-MM-DD</option>
                </flux:select>
                <flux:input wire:model="form.default_grace_days" :label="__('Default grace days')" type="number" min="0" max="60" />
                <flux:input wire:model="form.invoice_lead_days" :label="__('Invoice lead days')" type="number" min="0" max="60" />
                <flux:select wire:model="form.proration_basis" :label="__('Proration basis')">
                    @foreach ($prorationBases as $basis)
                        <option value="{{ $basis->value }}">{{ $basis->label() }}</option>
                    @endforeach
                </flux:select>
            </div>
        </flux:fieldset>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
```
No `#[Layout]` attribute is needed: like the kit's settings components, full-page components use Livewire's default app layout.

Create `routes/admin.php`:
```php
<?php

use App\Livewire\Admin\CompanySettings;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('settings', CompanySettings::class)->middleware('can:settings.manage')->name('settings');
});
```
In `routes/web.php` add after `require __DIR__.'/settings.php';`:
```php
require __DIR__.'/admin.php';
```

- [ ] **Step 7: The rms:setting command**

Create `app/Console/Commands/SetInstallSetting.php`:
```php
<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Models\CompanySetting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SetInstallSetting extends Command
{
    protected $signature = 'rms:setting {key : require_different_approver | go_live_at} {value : true|false, or Y-m-d|null}';

    protected $description = 'Change an install-level setting (spec §3). Audited.';

    public function handle(): int
    {
        $key = (string) $this->argument('key');
        $raw = (string) $this->argument('value');

        // [valid, value to store, value to show in the audit entry]
        [$valid, $value, $shown] = match (true) {
            $key === 'require_different_approver' && in_array($raw, ['true', 'false'], true) => [true, $raw === 'true', $raw === 'true'],
            $key === 'go_live_at' && $raw === 'null' => [true, null, null],
            $key === 'go_live_at' && CarbonImmutable::canBeCreatedFromFormat($raw, 'Y-m-d') => [true, CarbonImmutable::createFromFormat('Y-m-d', $raw)->startOfDay(), $raw],
            default => [false, null, null],
        };

        if (! $valid) {
            $this->error("Unknown key or invalid value: {$key} = {$raw}");

            return self::FAILURE;
        }

        $settings = CompanySetting::current();
        $old = $settings->getAttribute($key);

        $settings->forceFill([$key => $value])->save();

        Audit::log('settings.install_level.changed', $settings,
            [$key => $old instanceof \DateTimeInterface ? $old->format('Y-m-d') : $old],
            [$key => $shown],
        );

        $this->info("{$key} updated.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 8: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Settings
"$PHP" artisan test
```
Expected: 10 new tests pass; full suite passes.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add company settings and the rms:setting command

One settings row per install, enforced by a CHECK constraint, edited by
Admin and audited. The different-approver rule and go-live date can only
be changed on the server with rms:setting.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 8: Number sequences

**Spec:** §6.8 (gapless numbers per key and year, rows pre-created, 1 December job), §12

**Files:**
- Create: `app/Enums/NumberSequenceKey.php`, `database/migrations/2026_09_28_000500_create_number_sequences_table.php`, `app/Actions/NextDocumentNumber.php`, `app/Actions/EnsureNumberSequences.php`, `app/Console/Commands/EnsureNumberSequencesCommand.php`, `tests/Feature/Numbering/NextDocumentNumberTest.php`, `tests/Feature/Numbering/EnsureNumberSequencesTest.php`, `tests/Concurrency/NumberLockTest.php`
- Modify: `routes/console.php`, `config/services.php`

**Interfaces:**
- Consumes: Task 1 Concurrency suite
- Produces: `NumberSequenceKey` (8 cases, `prefix()`), `NextDocumentNumber::__invoke(NumberSequenceKey $key): string`, `EnsureNumberSequences::__invoke(int $year): int`, command `rms:number-sequences {--year=}`, schedule entry, `config('services.forge.heartbeats.number_sequences')`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Numbering/NextDocumentNumberTest.php`:
```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\NextDocumentNumber;
use App\Enums\NumberSequenceKey;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2026, 9, 28));
    app(EnsureNumberSequences::class)(2026);
});

test('numbers are formatted and consecutive', function () {
    $next = app(NextDocumentNumber::class);

    DB::transaction(function () use ($next) {
        expect($next(NumberSequenceKey::Invoice))->toBe('INV-2026-000001')
            ->and($next(NumberSequenceKey::Invoice))->toBe('INV-2026-000002')
            ->and($next(NumberSequenceKey::Agreement))->toBe('AGR-2026-000001');
    });
});

test('it refuses to run outside a transaction', function () {
    expect(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Invoice))->toThrow(LogicException::class);
});

test('it refuses when the year row is missing', function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2027, 1, 1));

    expect(fn () => DB::transaction(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Invoice)))
        ->toThrow(RuntimeException::class, 'No number_sequences row for [invoice, 2027].');
});

test('a rolled-back transaction gives its number back', function () {
    try {
        DB::transaction(function () {
            app(NextDocumentNumber::class)(NumberSequenceKey::Receipt);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(DB::transaction(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Receipt)))->toBe('RCP-2026-000001');
});

test('the year is the Bahrain year', function () {
    app(EnsureNumberSequences::class)(2027);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-12-31 21:30:00', 'UTC')); // 00:30 on 1 Jan 2027 in Bahrain

    expect(DB::transaction(fn () => app(NextDocumentNumber::class)(NumberSequenceKey::Invoice)))->toBe('INV-2027-000001');
});
```
Create `tests/Feature/Numbering/EnsureNumberSequencesTest.php`:
```php
<?php

use App\Actions\EnsureNumberSequences;
use Illuminate\Support\Facades\DB;

test('it creates one row per key with the right prefixes, once', function () {
    expect(app(EnsureNumberSequences::class)(2026))->toBe(8)
        ->and(app(EnsureNumberSequences::class)(2026))->toBe(0);

    expect(DB::table('number_sequences')->where('year', 2026)->orderBy('key')->pluck('prefix', 'key')->all())->toBe([
        'agreement' => 'AGR', 'credit_note' => 'CN', 'deposit_settlement' => 'DS', 'invoice' => 'INV',
        'owner_contract' => 'OC', 'owner_statement' => 'OS', 'payment_out' => 'PO', 'receipt' => 'RCP',
    ]);
});

test('the command defaults to next year', function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2026, 12, 1));

    $this->artisan('rms:number-sequences')->assertSuccessful();

    expect(DB::table('number_sequences')->where('year', 2027)->count())->toBe(8);
});
```
Create `tests/Concurrency/NumberLockTest.php`:
```php
<?php

use App\Actions\EnsureNumberSequences;
use App\Actions\NextDocumentNumber;
use App\Enums\NumberSequenceKey;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now('Asia/Bahrain')->setDate(2026, 9, 28));
    config(['database.connections.mysql_b' => config('database.connections.mysql')]);
    app(EnsureNumberSequences::class)(2026);
});

afterEach(fn () => DB::purge('mysql_b'));

it('blocks a second connection until the first commits', function () {
    $b = DB::connection('mysql_b');
    $b->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();
    expect(app(NextDocumentNumber::class)(NumberSequenceKey::Invoice))->toBe('INV-2026-000001');

    expect(fn () => $b->transaction(fn () => $b->table('number_sequences')->where('key', 'invoice')->where('year', 2026)->lockForUpdate()->first()))
        ->toThrow(fn (QueryException $e) => expect($e->errorInfo[1])->toBe(1205));

    DB::commit();

    expect($b->table('number_sequences')->where('key', 'invoice')->where('year', 2026)->value('next_value'))->toBe(2);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Numbering tests/Concurrency`
Expected: FAIL — `Class "App\Actions\EnsureNumberSequences" not found`.

- [ ] **Step 3: Enum and migration**

Create `app/Enums/NumberSequenceKey.php`:
```php
<?php

namespace App\Enums;

enum NumberSequenceKey: string
{
    case Agreement = 'agreement';
    case OwnerContract = 'owner_contract';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';
    case PaymentOut = 'payment_out';
    case OwnerStatement = 'owner_statement';
    case DepositSettlement = 'deposit_settlement';

    public function prefix(): string
    {
        return match ($this) {
            self::Agreement => 'AGR',
            self::OwnerContract => 'OC',
            self::Invoice => 'INV',
            self::CreditNote => 'CN',
            self::Receipt => 'RCP',
            self::PaymentOut => 'PO',
            self::OwnerStatement => 'OS',
            self::DepositSettlement => 'DS',
        };
    }
}
```
Create `database/migrations/2026_09_28_000500_create_number_sequences_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32);
            $table->unsignedSmallInteger('year');
            $table->string('prefix', 8);
            $table->unsignedInteger('next_value')->default(1);
            $table->unsignedTinyInteger('padding')->default(6);
            $table->timestamps();
            $table->unique(['key', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
```

- [ ] **Step 4: The two Actions**

Create `app/Actions/NextDocumentNumber.php`:
```php
<?php

namespace App\Actions;

use App\Enums\NumberSequenceKey;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/** Gapless document numbers (spec §6.8): the caller's transaction holds the row lock until it commits. */
final class NextDocumentNumber
{
    public function __invoke(NumberSequenceKey $key): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('NextDocumentNumber must run inside a DB::transaction().');
        }

        $year = now('Asia/Bahrain')->year;

        // Rows are pre-created (rms:install / 1 December job): a locking read of a missing row takes gap locks and deadlocks.
        $sequence = DB::table('number_sequences')
            ->where('key', $key->value)
            ->where('year', $year)
            ->lockForUpdate()
            ->first()
            ?? throw new RuntimeException("No number_sequences row for [{$key->value}, {$year}].");

        DB::table('number_sequences')->where('id', $sequence->id)->increment('next_value');

        return sprintf('%s-%d-%s', $sequence->prefix, $year, str_pad((string) $sequence->next_value, $sequence->padding, '0', STR_PAD_LEFT));
    }
}
```
Create `app/Actions/EnsureNumberSequences.php`:
```php
<?php

namespace App\Actions;

use App\Enums\NumberSequenceKey;
use Illuminate\Support\Facades\DB;

final class EnsureNumberSequences
{
    /** Creates any missing (key, year) rows; returns how many were created. */
    public function __invoke(int $year): int
    {
        $now = now();

        return DB::table('number_sequences')->insertOrIgnore(array_map(fn (NumberSequenceKey $key) => [
            'key' => $key->value,
            'year' => $year,
            'prefix' => $key->prefix(),
            'next_value' => 1,
            'padding' => 6,
            'created_at' => $now,
            'updated_at' => $now,
        ], NumberSequenceKey::cases()));
    }
}
```

- [ ] **Step 5: Command, schedule and heartbeat config**

Create `app/Console/Commands/EnsureNumberSequencesCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Actions\EnsureNumberSequences;
use Illuminate\Console\Command;

class EnsureNumberSequencesCommand extends Command
{
    protected $signature = 'rms:number-sequences {--year= : Defaults to next year (Asia/Bahrain)}';

    protected $description = 'Create the number_sequences rows for a year (spec §6.8)';

    public function handle(EnsureNumberSequences $ensure): int
    {
        $year = (int) ($this->option('year') ?: now('Asia/Bahrain')->year + 1);

        $this->info("Created {$ensure($year)} number sequence rows for {$year}.");

        return self::SUCCESS;
    }
}
```
Replace `routes/console.php` with:
```php
<?php

use Illuminate\Support\Facades\Schedule;

// Every job: no overlap (lock expires after 120 min if a run crashes) and a Forge heartbeat on success (spec §12).
// Heartbeat URLs are read with config(), never env(): env() is null after config:cache.

Schedule::command('rms:number-sequences')->yearlyOn(12, 1, '04:30')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.number_sequences')), (string) $url);
```
In `config/services.php` add inside the returned array:
```php
    'forge' => [
        'heartbeats' => [
            'number_sequences' => env('HEARTBEAT_NUMBER_SEQUENCES'),
        ],
    ],
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Numbering tests/Concurrency
"$PHP" artisan test
"$PHP" artisan schedule:list
```
Expected: 8 new tests pass; the full suite passes; `schedule:list` shows `rms:number-sequences` on 1 December at 04:30.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add gapless document number sequences

Numbers are taken under a row lock inside the caller's transaction, so a
rollback gives the number back. Rows are pre-created per year by install
and a 1 December job.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 9: Buildings and building scope

**Spec:** §4.1 (building fields), §8.2 (building assignment and the one scope)

**Files:**
- Create: `app/Enums/BuildingType.php`, `database/migrations/2026_09_28_000600_create_buildings_table.php`, `app/Models/Building.php`, `database/factories/BuildingFactory.php`, `app/Policies/BuildingPolicy.php`, `tests/Feature/Buildings/BuildingScopeTest.php`
- Modify: `app/Models/User.php`

**Interfaces:**
- Consumes: `PermissionName`, `RolesAndPermissionsSeeder`
- Produces: `Building` (`users()`, `visibleTo(User)` scope), `Building::factory()`, `BuildingPolicy::view/update`, `User::buildings(): BelongsToMany`, table `building_user`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Buildings/BuildingScopeTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->a, $this->b, $this->c] = Building::factory()->count(3)->create()->all();
});

test('roles with buildings.view-all see every building', function (RoleName $role) {
    $user = User::factory()->create()->assignRole($role);

    expect(Building::visibleTo($user)->count())->toBe(3);
})->with([RoleName::Admin, RoleName::Management, RoleName::Finance, RoleName::PropertyManager, RoleName::VendorSupport]);

test('Leasing sees only assigned buildings', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    expect(Building::visibleTo($user)->count())->toBe(0);

    $user->buildings()->attach([$this->a->id, $this->c->id]);

    expect(Building::visibleTo($user)->pluck('id')->sort()->values()->all())->toBe([$this->a->id, $this->c->id]);
});

test('soft-deleted buildings are never visible', function () {
    $this->b->delete();

    expect(Building::visibleTo(User::factory()->create()->assignRole(RoleName::Admin))->count())->toBe(2);
});

test('a leading orWhere cannot escape the scope', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    $user->buildings()->attach($this->a->id);

    $ids = Building::query()->where('id', $this->b->id)->orWhere('id', $this->c->id)->visibleTo($user)->pluck('id');

    expect($ids)->toBeEmpty();
});

test('the policy follows permission and scope', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->a->id);
    $manager = User::factory()->create()->assignRole(RoleName::PropertyManager);

    expect($leasing->can('view', $this->a))->toBeTrue()
        ->and($leasing->can('view', $this->b))->toBeFalse()
        ->and($leasing->can('update', $this->a))->toBeFalse()
        ->and($manager->can('update', $this->b))->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Buildings/BuildingScopeTest.php`
Expected: FAIL — `Class "App\Models\Building" not found`.

- [ ] **Step 3: Enum and migration**

Create `app/Enums/BuildingType.php`:
```php
<?php

namespace App\Enums;

enum BuildingType: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case Mixed = 'mixed';
}
```
Create `database/migrations/2026_09_28_000600_create_buildings_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('code', 30)->unique();
            $table->string('location', 150)->nullable();
            $table->text('address')->nullable();
            $table->string('type', 20)->default('residential');
            $table->unsignedSmallInteger('floors_count')->nullable();
            $table->string('parking', 150)->nullable();
            $table->text('facilities')->nullable();
            $table->foreignId('property_manager_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE buildings ADD CONSTRAINT buildings_type_chk CHECK (type IN ('residential', 'commercial', 'mixed'))");

        Schema::create('building_user', function (Blueprint $table) {
            $table->foreignId('building_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->primary(['building_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_user');
        Schema::dropIfExists('buildings');
    }
};
```

- [ ] **Step 4: Model, factory, policy, User relation**

Create `app/Models/Building.php`:
```php
<?php

namespace App\Models;

use App\Enums\BuildingType;
use App\Enums\PermissionName;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'location', 'address', 'type', 'floors_count', 'parking', 'facilities',
        'property_manager_user_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['type' => BuildingType::class, 'floors_count' => 'integer'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Spec §8.2 — the one place building scope is decided. Child models reuse it through
     * whereHas('building', fn ($q) => $q->visibleTo($user)).
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->can(PermissionName::BuildingsViewAll)) {
            return;
        }

        $query->whereIn(
            $query->qualifyColumn('id'),
            fn ($sub) => $sub->select('building_id')->from('building_user')->where('user_id', $user->getKey()),
        );
    }
}
```
Create `database/factories/BuildingFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Enums\BuildingType;
use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Tower',
            'code' => strtoupper(fake()->unique()->bothify('B-###')),
            'location' => fake()->randomElement(['Juffair', 'Seef', 'Manama', 'Amwaj']),
            'type' => BuildingType::Residential,
            'floors_count' => fake()->numberBetween(2, 20),
        ];
    }
}
```
Create `app/Policies/BuildingPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\Building;
use App\Models\User;

class BuildingPolicy
{
    public function view(User $user, Building $building): bool
    {
        return $user->can(PermissionName::BuildingsView) && self::inScope($user, $building);
    }

    public function update(User $user, Building $building): bool
    {
        return $user->can(PermissionName::BuildingsManage) && self::inScope($user, $building);
    }

    private static function inScope(User $user, Building $building): bool
    {
        return Building::visibleTo($user)->whereKey($building->getKey())->exists();
    }
}
```
(Laravel auto-discovers `App\Policies\BuildingPolicy` for `App\Models\Building`; no registration needed.)

In `app/Models/User.php` add `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` and the method:
```php
    /** @return BelongsToMany<Building, $this> */
    public function buildings(): BelongsToMany
    {
        return $this->belongsToMany(Building::class);
    }
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Buildings/BuildingScopeTest.php
"$PHP" artisan test
```
Expected: 9 new tests pass (the dataset counts 5); full suite passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add buildings and the building-assignment scope

Users without buildings.view-all see only their assigned buildings. The
scope lives in one place so later models reuse it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 10: User administration actions

**Spec:** §8.1 (self-change limits, Admin never sets passwords, notifications on sensitive grants and email changes, Vendor Support protections), §8.2 (building assignment), §8.6 (deactivation and 2FA reset kill sessions)

**Files:**
- Create: `app/Actions/Users/{CreateUser,UpdateUserProfile,SyncUserRoles,SyncUserBuildings,DeactivateUser,ReactivateUser,ResetUserTwoFactor}.php`, `app/Notifications/SensitiveAccessGranted.php`, `app/Notifications/UserEmailChanged.php`, `app/Support/Approvers.php`, `app/Policies/UserPolicy.php`, `tests/Feature/Users/UserActionsTest.php`

**Interfaces:**
- Consumes: `Audit::log`, `PermissionName::sensitiveGrants()`, `RoleName`, `User::logoutEverywhere()`, `User::isVendorSupport()`, `User::buildings()`, Fortify's container-bound disable action (Task 5)
- Produces: the seven Actions with the contract signatures; `SensitiveAccessGranted`, `UserEmailChanged`; `Approvers::notifiable(User ...$except): Collection<int, User>`; `UserPolicy` abilities `viewAny`, `create`, `update`, `deactivate`, `reactivate`, `resetTwoFactor`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Users/UserActionsTest.php`:
```php
<?php

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\ResetUserTwoFactor;
use App\Actions\Users\SyncUserBuildings;
use App\Actions\Users\SyncUserRoles;
use App\Actions\Users\UpdateUserProfile;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use App\Notifications\UserEmailChanged;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->approver = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
});

function auditEvent(string $event): ?Activity
{
    return Activity::query()->where('event', $event)->latest('id')->first();
}

test('CreateUser creates the user with roles and buildings and sends a reset link, never a password', function () {
    $building = Building::factory()->create();

    $user = app(CreateUser::class)->handle($this->admin, 'Sara Ali', 'sara@demo.test', ['leasing'], [$building->id]);

    expect($user->hasRole(RoleName::Leasing))->toBeTrue()
        ->and($user->buildings()->pluck('buildings.id')->all())->toBe([$building->id])
        ->and(auditEvent('user.created')->subject_id)->toBe($user->id);
    Notification::assertSentTo($user, ResetPassword::class);
});

test('only users.manage holders can create users', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    expect(fn () => app(CreateUser::class)->handle($leasing, 'X', 'x@demo.test', [], []))->toThrow(AuthorizationException::class);
});

test('a user cannot change their own roles', function () {
    expect(fn () => app(SyncUserRoles::class)->handle($this->admin, $this->admin, ['admin', 'finance']))
        ->toThrow(AuthorizationException::class);
});

test('roles changes are audited and sensitive grants notify the other approvers', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);

    app(SyncUserRoles::class)->handle($this->admin, $user, ['leasing', 'finance']);

    expect(auditEvent('user.roles.changed')->attribute_changes->toArray())->toEqual([
        'old' => ['roles' => ['leasing']], 'attributes' => ['roles' => ['finance', 'leasing']],
    ]);
    Notification::assertSentTo($this->approver, SensitiveAccessGranted::class);
    Notification::assertNotSentTo($user, SensitiveAccessGranted::class);
});

test('the Vendor Support role can neither be granted nor removed here', function () {
    $user = User::factory()->create();
    $vendor = User::factory()->create()->assignRole(RoleName::VendorSupport);

    expect(fn () => app(SyncUserRoles::class)->handle($this->admin, $user, ['vendor-support']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SyncUserRoles::class)->handle($this->admin, $vendor, []))->toThrow(AuthorizationException::class);
});

test('an unchanged role list writes no audit row', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);

    app(SyncUserRoles::class)->handle($this->admin, $user, ['leasing']);

    expect(auditEvent('user.roles.changed'))->toBeNull();
});

test('building assignments are audited', function () {
    $user = User::factory()->create()->assignRole(RoleName::Leasing);
    [$a, $b] = Building::factory()->count(2)->create()->all();

    app(SyncUserBuildings::class)->handle($this->admin, $user, [$a->id, $b->id]);

    expect(auditEvent('user.buildings.changed')->attribute_changes['attributes']['buildings'])->toBe([$a->id, $b->id]);
});

test('an email change notifies the old address and the approvers', function () {
    $user = User::factory()->create(['email' => 'old@demo.test']);

    app(UpdateUserProfile::class)->handle($this->admin, $user, $user->name, 'new@demo.test');

    expect($user->fresh()->email)->toBe('new@demo.test')->and(auditEvent('user.email.changed'))->not->toBeNull();
    Notification::assertSentOnDemand(UserEmailChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'old@demo.test');
    Notification::assertSentTo($this->approver, UserEmailChanged::class);
});

test('Vendor Support cannot be edited, but can be deactivated and not reactivated', function () {
    $vendor = User::factory()->create()->assignRole(RoleName::VendorSupport);

    expect(fn () => app(UpdateUserProfile::class)->handle($this->admin, $vendor, 'Renamed', $vendor->email))->toThrow(AuthorizationException::class);

    app(DeactivateUser::class)->handle($this->admin, $vendor);
    expect($vendor->fresh()->active)->toBeFalse();

    expect(fn () => app(ReactivateUser::class)->handle($this->admin, $vendor->fresh()))->toThrow(AuthorizationException::class);
});

test('deactivation kills sessions, is audited, and cannot target yourself', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    app(DeactivateUser::class)->handle($this->admin, $user);

    expect($user->fresh()->active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(auditEvent('user.deactivated'))->not->toBeNull();
    expect(fn () => app(DeactivateUser::class)->handle($this->admin, $this->admin))->toThrow(AuthorizationException::class);

    app(ReactivateUser::class)->handle($this->admin, $user->fresh());
    expect($user->fresh()->active)->toBeTrue()->and(auditEvent('user.reactivated'))->not->toBeNull();
});

test('resetting another user\'s 2FA clears it and kills sessions; resetting your own is refused', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);
    DB::table('sessions')->insert(['id' => 's2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $this->actingAs($this->admin);

    app(ResetUserTwoFactor::class)->handle($this->admin, $user);

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(auditEvent('user.2fa.reset'))->not->toBeNull();
    expect(fn () => app(ResetUserTwoFactor::class)->handle($this->admin, $this->admin))->toThrow(AuthorizationException::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Users/UserActionsTest.php`
Expected: FAIL — `Class "App\Actions\Users\CreateUser" not found`.

- [ ] **Step 3: Approvers helper and notifications**

Create `app/Support/Approvers.php`:
```php
<?php

namespace App\Support;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Collection;

final class Approvers
{
    /** Active approvals.decide holders, never Vendor Support (spec §8.1: no business emails), minus $except. @return Collection<int, User> */
    public static function notifiable(User ...$except): Collection
    {
        $excluded = array_map(fn (User $user) => $user->getKey(), $except);

        return User::query()
            ->where('active', true)
            ->permission(PermissionName::ApprovalsDecide->value)
            ->whereKeyNot($excluded)
            ->get()
            ->reject(fn (User $user) => $user->hasRole(RoleName::VendorSupport))
            ->values();
    }
}
```
Create `app/Notifications/SensitiveAccessGranted.php`:
```php
<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SensitiveAccessGranted extends Notification
{
    /** @param  list<string>  $permissions */
    public function __construct(public User $subject, public array $permissions, public User $actor) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Sensitive access granted to :name', ['name' => $this->subject->name]))
            ->line(__(':actor granted :name these permissions: :permissions.', [
                'actor' => $this->actor->name,
                'name' => $this->subject->name,
                'permissions' => implode(', ', $this->permissions),
            ]))
            ->line(__('If you did not expect this, review the change in the audit log.'));
    }
}
```
Create `app/Notifications/UserEmailChanged.php`:
```php
<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserEmailChanged extends Notification
{
    public function __construct(public User $subject, public string $oldEmail, public string $newEmail, public User $actor) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Email address changed for :name', ['name' => $this->subject->name]))
            ->line(__(':actor changed the sign-in email of :name from :old to :new.', [
                'actor' => $this->actor->name,
                'name' => $this->subject->name,
                'old' => $this->oldEmail,
                'new' => $this->newEmail,
            ]))
            ->line(__('If you did not expect this, contact your administrator.'));
    }
}
```

- [ ] **Step 4: The policy**

Create `app/Policies/UserPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(PermissionName::UsersManage);
    }

    public function create(User $actor): bool
    {
        return $actor->can(PermissionName::UsersManage);
    }

    /** Vendor Support can't be edited by Admin (spec §8.1). */
    public function update(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $user->isVendorSupport();
    }

    public function deactivate(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $actor->is($user) && $user->active;
    }

    /** Vendor Support is re-enabled only with rms:vendor-support on the server. */
    public function reactivate(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $user->isVendorSupport() && ! $user->active;
    }

    public function resetTwoFactor(User $actor, User $user): bool
    {
        return $actor->can(PermissionName::UsersManage) && ! $actor->is($user) && ! $user->isVendorSupport();
    }
}
```

- [ ] **Step 5: The Actions**

Create `app/Actions/Users/SyncUserRoles.php`:
```php
<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use App\Support\Approvers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class SyncUserRoles
{
    /** @param  list<string>  $roles  role names (RoleName values) */
    public function handle(User $actor, User $user, array $roles): void
    {
        if (! $actor->can(PermissionName::UsersManage)) {
            throw new AuthorizationException;
        }
        if ($actor->is($user)) {
            throw new AuthorizationException(__('You cannot change your own roles.'));
        }
        if (in_array(RoleName::VendorSupport->value, $roles, true) || $user->isVendorSupport()) {
            throw new AuthorizationException(__('The Vendor Support role is managed on the server only.'));
        }

        DB::transaction(function () use ($actor, $user, $roles) {
            $oldRoles = $user->getRoleNames()->sort()->values()->all();
            $oldPermissions = $user->getAllPermissions()->pluck('name')->all();

            $user->syncRoles($roles);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            $newRoles = $user->getRoleNames()->sort()->values()->all();
            if ($oldRoles === $newRoles) {
                return;
            }

            Audit::log('user.roles.changed', $user, ['roles' => $oldRoles], ['roles' => $newRoles], causer: $actor);

            $granted = array_values(array_intersect(
                array_diff($user->getAllPermissions()->pluck('name')->all(), $oldPermissions),
                array_map(fn (PermissionName $p) => $p->value, PermissionName::sensitiveGrants()),
            ));

            if ($granted !== []) {
                Notification::send(Approvers::notifiable($actor, $user), new SensitiveAccessGranted($user, $granted, $actor));
            }
        });
    }
}
```
Create `app/Actions/Users/SyncUserBuildings.php`:
```php
<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class SyncUserBuildings
{
    /** @param  list<int>  $buildingIds */
    public function handle(User $actor, User $user, array $buildingIds): void
    {
        if (! $actor->can(PermissionName::UsersManage)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user, $buildingIds) {
            $old = $user->buildings()->orderBy('buildings.id')->pluck('buildings.id')->all();
            $new = collect($buildingIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            if ($old === $new) {
                return;
            }

            $user->buildings()->sync($new);

            Audit::log('user.buildings.changed', $user, ['buildings' => $old], ['buildings' => $new], causer: $actor);
        });
    }
}
```
Create `app/Actions/Users/CreateUser.php`:
```php
<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CreateUser
{
    public function __construct(private SyncUserRoles $roles, private SyncUserBuildings $buildings) {}

    /**
     * Admin never sets passwords (spec §8.1): the user gets a random one nobody knows, plus a reset link.
     *
     * @param  list<string>  $roles
     * @param  list<int>  $buildingIds
     */
    public function handle(User $actor, string $name, string $email, array $roles, array $buildingIds): User
    {
        if (! $actor->can(PermissionName::UsersManage)) {
            throw new AuthorizationException;
        }

        $data = Validator::make(['name' => $name, 'email' => Str::lower($email)], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ])->validate();

        $user = DB::transaction(function () use ($actor, $data, $roles, $buildingIds) {
            $user = User::create([...$data, 'password' => Str::password(40)]);

            Audit::log('user.created', $user, new: ['name' => $user->name, 'email' => $user->email], causer: $actor);

            $this->roles->handle($actor, $user, $roles);
            $this->buildings->handle($actor, $user, $buildingIds);

            return $user;
        });

        Password::broker()->sendResetLink(['email' => $user->email]);

        return $user;
    }
}
```
Create `app/Actions/Users/UpdateUserProfile.php`:
```php
<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use App\Notifications\UserEmailChanged;
use App\Support\Approvers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class UpdateUserProfile
{
    public function handle(User $actor, User $user, string $name, string $email): void
    {
        if (! $actor->can('update', $user)) {
            throw new AuthorizationException;
        }

        $data = Validator::make(['name' => $name, 'email' => Str::lower($email)], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ])->validate();

        $oldEmail = $user->email;

        DB::transaction(function () use ($actor, $user, $data, $oldEmail) {
            $user->fill($data)->save(); // model logging records 'updated' with old/new

            if ($oldEmail !== $user->email) {
                Audit::log('user.email.changed', $user, ['email' => $oldEmail], ['email' => $user->email], causer: $actor);
            }
        });

        if ($oldEmail !== $user->email) {
            $notice = new UserEmailChanged($user, $oldEmail, $user->email, $actor);
            Notification::route('mail', $oldEmail)->notify($notice);
            Notification::send(Approvers::notifiable($actor, $user), $notice);
        }
    }
}
```
Create `app/Actions/Users/DeactivateUser.php`:
```php
<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DeactivateUser
{
    public function handle(User $actor, User $user): void
    {
        if (! $actor->can('deactivate', $user)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['active' => false])->save();
            $user->logoutEverywhere();

            Audit::log('user.deactivated', $user, causer: $actor);
        });
    }
}
```
Create `app/Actions/Users/ReactivateUser.php`:
```php
<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class ReactivateUser
{
    public function handle(User $actor, User $user): void
    {
        if (! $actor->can('reactivate', $user)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user) {
            $user->forceFill(['active' => true])->save();

            Audit::log('user.reactivated', $user, causer: $actor);
        });
    }
}
```
Create `app/Actions/Users/ResetUserTwoFactor.php`:
```php
<?php

namespace App\Actions\Users;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

final class ResetUserTwoFactor
{
    public function __construct(private DisableTwoFactorAuthentication $disable) {}

    public function handle(User $actor, User $user): void
    {
        if (! $actor->can('resetTwoFactor', $user)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $user) {
            ($this->disable)($user); // resolves to App\Actions\Fortify\DisableTwoFactorAuthentication (Task 5)
            $user->logoutEverywhere();

            Audit::log('user.2fa.reset', $user, causer: $actor);
        });
    }
}
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Users/UserActionsTest.php
"$PHP" artisan test
```
Expected: 11 new tests pass; full suite passes.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add user administration actions

Create users with a reset link, change roles and buildings, deactivate,
reactivate and reset 2FA, all audited. Nobody changes their own roles,
Vendor Support is protected, and sensitive grants or email changes are
emailed to the other approvers.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 11: User administration screens

**Spec:** §8.1, §8.2 (user management UI), §2 (usable at 375 px, free Flux only)

**Files:**
- Create: `app/Livewire/Admin/Users/Index.php`, `app/Livewire/Admin/Users/Form.php`, `resources/views/livewire/admin/users/index.blade.php`, `resources/views/livewire/admin/users/form.blade.php`, `tests/Feature/Users/UserScreensTest.php`
- Modify: `routes/admin.php`

**Interfaces:**
- Consumes: Task 10 Actions and `UserPolicy`, `Building`, `RoleName`
- Produces: routes `admin.users.index`, `admin.users.create`, `admin.users.edit`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Users/UserScreensTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Livewire\Admin\Users\Form;
use App\Livewire\Admin\Users\Index;
use App\Models\Building;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Admin One'])->assignRole(RoleName::Admin);
});

test('only users.manage holders reach the screens', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($leasing)->get(route('admin.users.create'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertSee('Admin One');
});

test('the list searches by name or email and filters inactive users', function () {
    User::factory()->create(['name' => 'Sara Ali', 'email' => 'sara@demo.test']);
    User::factory()->inactive()->create(['name' => 'Old Clerk']);

    Livewire::actingAs($this->admin)->test(Index::class)
        ->set('search', 'sara@')
        ->assertSee('Sara Ali')->assertDontSee('Admin One')
        ->set('search', '')
        ->set('status', 'inactive')
        ->assertSee('Old Clerk')->assertDontSee('Sara Ali');
});

test('an admin creates a user with roles and buildings', function () {
    $building = Building::factory()->create();

    Livewire::actingAs($this->admin)->test(Form::class)
        ->set('name', 'Sara Ali')
        ->set('email', 'sara@demo.test')
        ->set('roles', ['leasing'])
        ->set('buildingIds', [$building->id])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    $user = User::where('email', 'sara@demo.test')->firstOrFail();
    expect($user->hasRole(RoleName::Leasing))->toBeTrue()->and($user->buildings()->count())->toBe(1);
});

test('the Vendor Support role is never offered', function () {
    Livewire::actingAs($this->admin)->test(Form::class)->assertDontSee('Vendor support');
});

test('changing your own roles shows an error instead of saving', function () {
    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $this->admin])
        ->set('roles', ['admin', 'finance'])
        ->call('save')
        ->assertHasErrors('roles');

    expect($this->admin->fresh()->hasRole(RoleName::Finance))->toBeFalse();
});

test('the Vendor Support account is read-only', function () {
    $vendor = User::factory()->create()->assignRole(RoleName::VendorSupport);

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $vendor])
        ->assertSee('managed on the server')
        ->assertDontSee('Save')
        ->assertSee('Deactivate');
});

test('actions follow the policy', function () {
    $user = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Finance);

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $user])
        ->assertSee('Deactivate')->assertSee('Reset 2FA')->assertDontSee('Reactivate')
        ->call('deactivate')
        ->assertSee('Reactivate');

    Livewire::actingAs($this->admin)->test(Form::class, ['user' => $this->admin])
        ->assertDontSee('Deactivate')->assertDontSee('Reset 2FA');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Users/UserScreensTest.php`
Expected: FAIL — `Route [admin.users.index] not defined`.

- [ ] **Step 3: Routes**

In `routes/admin.php` add `use App\Livewire\Admin\Users;` at the top and, inside the group:
```php
    Route::livewire('users', Users\Index::class)->middleware('can:users.manage')->name('users.index');
    Route::livewire('users/create', Users\Form::class)->middleware('can:users.manage')->name('users.create');
    Route::livewire('users/{user}/edit', Users\Form::class)->middleware('can:users.manage')->name('users.edit');
```

- [ ] **Step 4: The list**

Create `app/Livewire/Admin/Users/Index.php`:
```php
<?php

namespace App\Livewire\Admin\Users;

use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Users')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'active'; // active | inactive | all

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $users = User::query()
            ->with('roles')
            ->withCount('buildings')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('email', 'like', '%'.$this->search.'%')))
            ->when($this->status !== 'all', fn ($q) => $q->where('active', $this->status === 'active'))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.admin.users.index', ['users' => $users]);
    }
}
```
Create `resources/views/livewire/admin/users/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
        <flux:button variant="primary" :href="route('admin.users.create')" wire:navigate>{{ __('New user') }}</flux:button>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search name or email')" icon="magnifying-glass" class="sm:max-w-xs" />
        <flux:select wire:model.live="status" class="sm:max-w-40">
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
            <option value="all">{{ __('All') }}</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$users">
            <flux:table.columns>
                <flux:table.column>{{ __('Name') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column>{{ __('Roles') }}</flux:table.column>
                <flux:table.column>{{ __('Buildings') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($users as $user)
                    <flux:table.row :key="$user->id">
                        <flux:table.cell>
                            <flux:link :href="route('admin.users.edit', $user)" wire:navigate>{{ $user->name }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->email }}</flux:table.cell>
                        <flux:table.cell>
                            {{ $user->roles->map(fn ($role) => \App\Enums\RoleName::from($role->name)->label())->join(', ') }}
                        </flux:table.cell>
                        <flux:table.cell>{{ $user->buildings_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge :color="$user->active ? 'green' : 'zinc'" size="sm">{{ $user->active ? __('Active') : __('Inactive') }}</flux:badge>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 5: The form**

Create `app/Livewire/Admin/Users/Form.php`:
```php
<?php

namespace App\Livewire\Admin\Users;

use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\ResetUserTwoFactor;
use App\Actions\Users\SyncUserBuildings;
use App\Actions\Users\SyncUserRoles;
use App\Actions\Users\UpdateUserProfile;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    #[Locked]
    public ?int $userId = null;

    public string $name = '';

    public string $email = '';

    /** @var list<string> */
    public array $roles = [];

    /** @var list<int> */
    public array $buildingIds = [];

    public function mount(?User $user = null): void
    {
        if ($user?->exists) {
            $this->userId = $user->id;
            $this->name = $user->name;
            $this->email = $user->email;
            $this->roles = $user->getRoleNames()->all();
            $this->buildingIds = $user->buildings()->pluck('buildings.id')->all();
        }
    }

    public function user(): ?User
    {
        return $this->userId ? User::findOrFail($this->userId) : null;
    }

    public function save(CreateUser $create, UpdateUserProfile $profile, SyncUserRoles $roles, SyncUserBuildings $buildings): void
    {
        $actor = auth()->user();

        try {
            if (! $user = $this->user()) {
                $create->handle($actor, $this->name, $this->email, $this->roles, $this->buildingIds);
            } else {
                DB::transaction(function () use ($actor, $user, $profile, $roles, $buildings) {
                    $profile->handle($actor, $user, $this->name, $this->email);
                    if ($user->getRoleNames()->sort()->values()->all() !== collect($this->roles)->sort()->values()->all()) {
                        $roles->handle($actor, $user, $this->roles);
                    }
                    $buildings->handle($actor, $user, $this->buildingIds);
                });
            }
        } catch (AuthorizationException $e) {
            $this->addError('roles', $e->getMessage() ?: __('This action is not allowed.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('User saved.'));
        $this->redirectRoute('admin.users.index', navigate: true);
    }

    public function deactivate(DeactivateUser $action): void
    {
        $this->run(fn () => $action->handle(auth()->user(), $this->user()), __('User deactivated.'));
    }

    public function reactivate(ReactivateUser $action): void
    {
        $this->run(fn () => $action->handle(auth()->user(), $this->user()), __('User reactivated.'));
    }

    public function resetTwoFactor(ResetUserTwoFactor $action): void
    {
        $this->run(fn () => $action->handle(auth()->user(), $this->user()), __('Two-factor authentication reset.'));
    }

    private function run(callable $action, string $message): void
    {
        try {
            $action();
            Flux::toast(variant: 'success', text: $message);
        } catch (AuthorizationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage() ?: __('This action is not allowed.'));
        }
    }

    public function render()
    {
        return view('livewire.admin.users.form', [
            'subject' => $this->user(),
            'roleOptions' => array_filter(RoleName::cases(), fn (RoleName $role) => $role !== RoleName::VendorSupport),
            'buildings' => Building::query()->orderBy('name')->get(['id', 'name', 'code']),
        ])->title($this->userId ? __('Edit user') : __('New user'));
    }
}
```
Create `resources/views/livewire/admin/users/form.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $subject ? __('Edit user') : __('New user') }}</flux:heading>

    @if ($subject?->isVendorSupport())
        <flux:callout variant="warning" icon="lock-closed" :heading="__('The Vendor Support account is managed on the server. You can only deactivate it.')" />
    @else
        <form wire:submit="save" class="space-y-6">
            <flux:input wire:model="name" :label="__('Name')" required />
            <flux:input wire:model="email" :label="__('Email')" type="email" required />

            <flux:checkbox.group wire:model="roles" :label="__('Roles')">
                @foreach ($roleOptions as $role)
                    <flux:checkbox :value="$role->value" :label="$role->label()" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="roles" />

            <flux:checkbox.group wire:model="buildingIds" :label="__('Assigned buildings')" :description="__('Only matters for users without access to all buildings.')">
                @forelse ($buildings as $building)
                    <flux:checkbox :value="$building->id" :label="$building->code.' — '.$building->name" />
                @empty
                    <flux:text>{{ __('No buildings yet.') }}</flux:text>
                @endforelse
            </flux:checkbox.group>

            @unless ($subject)
                <flux:text>{{ __('The new user receives an email with a link to set their own password.') }}</flux:text>
            @endunless

            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </form>
    @endif

    @if ($subject)
        <div class="flex flex-wrap gap-3 border-t border-zinc-200 pt-6 dark:border-zinc-700">
            @can('deactivate', $subject)
                <flux:button variant="danger" wire:click="deactivate" wire:confirm="{{ __('Deactivate this user and sign them out everywhere?') }}">{{ __('Deactivate') }}</flux:button>
            @endcan
            @can('reactivate', $subject)
                <flux:button wire:click="reactivate">{{ __('Reactivate') }}</flux:button>
            @endcan
            @can('resetTwoFactor', $subject)
                <flux:button wire:click="resetTwoFactor" wire:confirm="{{ __('Remove this user\'s two-factor setup and sign them out?') }}">{{ __('Reset 2FA') }}</flux:button>
            @endcan
        </div>
    @endif
</section>
```

- [ ] **Step 6: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Users/UserScreensTest.php
"$PHP" artisan test
```
Expected: 7 new tests pass; full suite passes. Then open http://rms.test/admin/users at 375 px width (browser dev tools) and check the table scrolls horizontally and the form stacks in one column.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the user administration screens

List, search, create and edit users, assign roles and buildings, and
deactivate, reactivate or reset 2FA, all through the audited actions.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 12: Roles administration

**Spec:** §8.1 (Admin edits role permissions, audited; cannot edit a role they hold; sensitive grants notify approvers)

**Files:**
- Create: `app/Actions/Roles/SyncRolePermissions.php`, `app/Livewire/Admin/Roles/Index.php`, `app/Livewire/Admin/Roles/Edit.php`, `resources/views/livewire/admin/roles/index.blade.php`, `resources/views/livewire/admin/roles/edit.blade.php`, `tests/Feature/Roles/RolesAdministrationTest.php`
- Modify: `routes/admin.php`

**Interfaces:**
- Consumes: `Audit`, `Approvers`, `SensitiveAccessGranted`, `PermissionName`, `RoleName`
- Produces: `SyncRolePermissions::handle(User $actor, Role $role, array $permissions): void`; routes `admin.roles.index`, `admin.roles.edit`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Roles/RolesAdministrationTest.php`:
```php
<?php

use App\Actions\Roles\SyncRolePermissions;
use App\Enums\RoleName;
use App\Livewire\Admin\Roles\Edit;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->admin = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin);
    $this->approver = User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management);
});

test('only roles.manage holders reach the screens', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    $this->actingAs($leasing)->get(route('admin.roles.index'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.roles.index'))->assertOk()->assertSee('Property manager');
});

test('editing a role saves, audits old and new, and takes effect immediately', function () {
    $leasing = Role::findByName('leasing');
    $member = User::factory()->create()->assignRole(RoleName::Leasing);
    $old = $leasing->permissions->pluck('name')->sort()->values()->all();

    Livewire::actingAs($this->admin)->test(Edit::class, ['role' => $leasing])
        ->set('permissions', [...$old, 'owners.view'])
        ->call('save')
        ->assertHasNoErrors();

    expect($member->fresh()->can('owners.view'))->toBeTrue();
    $row = Activity::query()->where('event', 'role.permissions.changed')->firstOrFail();
    expect($row->attribute_changes['old']['permissions'])->toBe($old)
        ->and($row->attribute_changes['attributes']['permissions'])->toContain('owners.view');
});

test('a role you hold is read-only', function () {
    expect(fn () => app(SyncRolePermissions::class)->handle($this->admin, Role::findByName('admin'), ['users.manage']))
        ->toThrow(AuthorizationException::class);
});

test('the Vendor Support role is read-only', function () {
    expect(fn () => app(SyncRolePermissions::class)->handle($this->admin, Role::findByName('vendor-support'), []))
        ->toThrow(AuthorizationException::class);
});

test('adding a sensitive permission notifies approvers about each holder', function () {
    $member = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing = Role::findByName('leasing');

    app(SyncRolePermissions::class)->handle($this->admin, $leasing, [...$leasing->permissions->pluck('name'), 'payments.manage']);

    Notification::assertSentTo($this->approver, SensitiveAccessGranted::class, fn ($n) => $n->subject->is($member) && $n->permissions === ['payments.manage']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Roles/RolesAdministrationTest.php`
Expected: FAIL — `Route [admin.roles.index] not defined`.

- [ ] **Step 3: The Action**

Create `app/Actions/Roles/SyncRolePermissions.php`:
```php
<?php

namespace App\Actions\Roles;

use App\Audit\Audit;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\SensitiveAccessGranted;
use App\Support\Approvers;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

final class SyncRolePermissions
{
    /** @param  list<string>  $permissions  permission names */
    public function handle(User $actor, Role $role, array $permissions): void
    {
        if (! $actor->can(PermissionName::RolesManage)) {
            throw new AuthorizationException;
        }
        if ($actor->hasRole($role->name)) {
            throw new AuthorizationException(__('You cannot change a role you hold.'));
        }
        if ($role->name === RoleName::VendorSupport->value) {
            throw new AuthorizationException(__('The Vendor Support role is managed on the server only.'));
        }

        $valid = array_map(fn (PermissionName $p) => $p->value, PermissionName::cases());
        $new = collect($permissions)->intersect($valid)->unique()->sort()->values()->all();

        DB::transaction(function () use ($actor, $role, $new) {
            $old = $role->permissions()->pluck('name')->sort()->values()->all();
            if ($old === $new) {
                return;
            }

            $role->syncPermissions($new); // also clears the permission cache

            Audit::log('role.permissions.changed', $role, ['permissions' => $old], ['permissions' => $new], causer: $actor);

            $granted = array_values(array_intersect(
                array_diff($new, $old),
                array_map(fn (PermissionName $p) => $p->value, PermissionName::sensitiveGrants()),
            ));

            if ($granted === []) {
                return;
            }

            foreach (User::role($role->name)->get() as $holder) {
                Notification::send(Approvers::notifiable($actor, $holder), new SensitiveAccessGranted($holder, $granted, $actor));
            }
        });
    }
}
```

- [ ] **Step 4: Screens and routes**

In `routes/admin.php` add `use App\Livewire\Admin\Roles;` and inside the group:
```php
    Route::livewire('roles', Roles\Index::class)->middleware('can:roles.manage')->name('roles.index');
    Route::livewire('roles/{role}/edit', Roles\Edit::class)->middleware('can:roles.manage')->name('roles.edit');
```
Create `app/Livewire/Admin/Roles/Index.php`:
```php
<?php

namespace App\Livewire\Admin\Roles;

use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;

#[Title('Roles')]
class Index extends Component
{
    public function render()
    {
        return view('livewire.admin.roles.index', [
            'roles' => Role::query()->withCount(['permissions', 'users'])->orderBy('name')->get(),
        ]);
    }
}
```
Create `resources/views/livewire/admin/roles/index.blade.php`:
```blade
<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Roles') }}</flux:heading>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Users') }}</flux:table.column>
                <flux:table.column>{{ __('Permissions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($roles as $role)
                    <flux:table.row :key="$role->id">
                        <flux:table.cell>
                            <flux:link :href="route('admin.roles.edit', $role)" wire:navigate>{{ \App\Enums\RoleName::from($role->name)->label() }}</flux:link>
                        </flux:table.cell>
                        <flux:table.cell>{{ $role->users_count }}</flux:table.cell>
                        <flux:table.cell>{{ $role->permissions_count }}</flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```
Create `app/Livewire/Admin/Roles/Edit.php`:
```php
<?php

namespace App\Livewire\Admin\Roles;

use App\Actions\Roles\SyncRolePermissions;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Permission\Models\Role;

class Edit extends Component
{
    #[Locked]
    public int $roleId;

    /** @var list<string> */
    public array $permissions = [];

    public function mount(Role $role): void
    {
        $this->roleId = $role->id;
        $this->permissions = $role->permissions()->pluck('name')->all();
    }

    public function role(): Role
    {
        return Role::findOrFail($this->roleId);
    }

    public function readOnly(): bool
    {
        $role = $this->role();

        return $role->name === RoleName::VendorSupport->value || auth()->user()->hasRole($role->name);
    }

    public function save(SyncRolePermissions $sync): void
    {
        try {
            $sync->handle(auth()->user(), $this->role(), $this->permissions);
        } catch (AuthorizationException $e) {
            $this->addError('permissions', $e->getMessage() ?: __('This action is not allowed.'));

            return;
        }

        Flux::toast(variant: 'success', text: __('Role saved.'));
    }

    public function render()
    {
        $groups = collect(PermissionName::cases())
            ->groupBy(fn (PermissionName $p) => str($p->value)->before('.')->headline()->toString());

        return view('livewire.admin.roles.edit', [
            'label' => RoleName::from($this->role()->name)->label(),
            'groups' => $groups,
            'readOnly' => $this->readOnly(),
        ])->title(__('Edit role'));
    }
}
```
Create `resources/views/livewire/admin/roles/edit.blade.php`:
```blade
<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ $label }}</flux:heading>

    @if ($readOnly)
        <flux:callout variant="warning" icon="lock-closed" :heading="__('This role is read-only for you: it is either a role you hold or the Vendor Support role.')" />
    @endif

    <form wire:submit="save" class="space-y-6">
        @foreach ($groups as $group => $cases)
            <flux:checkbox.group wire:model="permissions" :label="$group" :disabled="$readOnly">
                @foreach ($cases as $permission)
                    <flux:checkbox :value="$permission->value" :label="$permission->value" />
                @endforeach
            </flux:checkbox.group>
        @endforeach
        <flux:error name="permissions" />

        @unless ($readOnly)
            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        @endunless
    </form>
</section>
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Roles/RolesAdministrationTest.php
"$PHP" artisan test
```
Expected: 5 new tests pass; full suite passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add roles administration

Admin edits which permissions each role has; changes are audited, a role
you hold and the Vendor Support role are read-only, and new sensitive
permissions notify the approvers.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 13: Audit log viewer

**Spec:** §8.4 (read-only to Admin and Management)

**Files:**
- Create: `app/Livewire/Admin/AuditLog.php`, `resources/views/livewire/admin/audit-log.blade.php`, `tests/Feature/Audit/AuditLogViewerTest.php`
- Modify: `routes/admin.php`

**Interfaces:**
- Consumes: `Audit::log`, `activity_log`
- Produces: route `admin.audit`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Audit/AuditLogViewerTest.php`:
```php
<?php

use App\Audit\Audit;
use App\Enums\RoleName;
use App\Livewire\Admin\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Admin One'])->assignRole(RoleName::Admin);
});

test('only audit.view holders can open it', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleName::Finance))->get(route('admin.audit'))->assertRedirect(route('security.edit'));
    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))->get(route('admin.audit'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.audit'))->assertOk();
    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management))->get(route('admin.audit'))->assertOk();
});

test('it filters by event, user, subject type and date, newest first', function () {
    $other = User::factory()->create(['name' => 'Other Person']);
    $this->travelTo(now()->subDays(3));
    Audit::log('user.deactivated', $other, causer: $this->admin);
    $this->travelBack();
    Audit::log('document.downloaded', causer: $other);

    Livewire::actingAs($this->admin)->test(AuditLog::class)
        ->assertSeeInOrder(['document.downloaded', 'user.deactivated'])
        ->set('event', 'deactivated')
        ->assertSee('user.deactivated')->assertDontSee('document.downloaded')
        ->set('event', '')
        ->set('causerId', $other->id)
        ->assertSee('document.downloaded')->assertDontSee('user.deactivated')
        ->set('causerId', null)
        ->set('subjectType', (new User)->getMorphClass())
        ->assertSee('user.deactivated')->assertDontSee('document.downloaded')
        ->set('subjectType', '')
        ->set('from', now()->subDay()->toDateString())
        ->assertSee('document.downloaded')->assertDontSee('user.deactivated');
});

test('it offers no way to change entries', function () {
    Audit::log('user.deactivated', causer: $this->admin);

    Livewire::actingAs($this->admin)->test(AuditLog::class)
        ->assertDontSee('Delete')->assertDontSee('Edit');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Audit/AuditLogViewerTest.php`
Expected: FAIL — `Route [admin.audit] not defined`.

- [ ] **Step 3: Route, component, view**

In `routes/admin.php` add `use App\Livewire\Admin\AuditLog;` and inside the group:
```php
    Route::livewire('audit', AuditLog::class)->middleware('can:audit.view')->name('audit');
```
Create `app/Livewire/Admin/AuditLog.php`:
```php
<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

#[Title('Audit log')]
class AuditLog extends Component
{
    use WithPagination;

    #[Url]
    public string $event = '';

    #[Url]
    public ?int $causerId = null;

    #[Url]
    public string $subjectType = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $entries = Activity::query()
            ->with('causer')
            ->when($this->event !== '', fn ($q) => $q->where('event', 'like', '%'.$this->event.'%'))
            ->when($this->causerId, fn ($q) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $this->causerId))
            ->when($this->subjectType !== '', fn ($q) => $q->where('subject_type', $this->subjectType))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', $this->from.' 00:00:00'))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', $this->to.' 23:59:59'))
            ->orderByDesc('id')
            ->paginate(50);

        return view('livewire.admin.audit-log', [
            'entries' => $entries,
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
            'subjectTypes' => Activity::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type'),
        ]);
    }
}
```
Create `resources/views/livewire/admin/audit-log.blade.php`:
```blade
<section class="w-full space-y-6">
    <flux:heading size="xl" level="1">{{ __('Audit log') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <flux:input wire:model.live.debounce.300ms="event" :label="__('Event')" />
        <flux:select wire:model.live="causerId" :label="__('User')">
            <option value="">{{ __('Anyone') }}</option>
            @foreach ($users as $user)
                <option value="{{ $user->id }}">{{ $user->name }}</option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="subjectType" :label="__('Record type')">
            <option value="">{{ __('Any') }}</option>
            @foreach ($subjectTypes as $type)
                <option value="{{ $type }}">{{ class_basename($type) }}</option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="from" type="date" :label="__('From')" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" />
    </div>

    <div class="overflow-x-auto">
        <flux:table :paginate="$entries">
            <flux:table.columns>
                <flux:table.column>{{ __('Time') }}</flux:table.column>
                <flux:table.column>{{ __('User') }}</flux:table.column>
                <flux:table.column>{{ __('Event') }}</flux:table.column>
                <flux:table.column>{{ __('Record') }}</flux:table.column>
                <flux:table.column>{{ __('IP') }}</flux:table.column>
                <flux:table.column>{{ __('Changes') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($entries as $entry)
                    <flux:table.row :key="$entry->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $entry->created_at->timezone('Asia/Bahrain')->format('d/m/Y H:i:s') }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->causer?->name ?? __('System') }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->event }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->subject_type ? class_basename($entry->subject_type).' #'.$entry->subject_id : '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $entry->ip ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($entry->attribute_changes?->isNotEmpty() || $entry->properties?->isNotEmpty())
                                <details>
                                    <summary class="cursor-pointer text-sm">{{ __('Show') }}</summary>
                                    <pre class="mt-2 max-w-md overflow-x-auto text-xs">{{ json_encode(['changes' => $entry->attribute_changes, 'properties' => $entry->properties], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                </details>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</section>
```

- [ ] **Step 4: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Audit/AuditLogViewerTest.php
"$PHP" artisan test
```
Expected: 3 new tests pass; full suite passes.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the read-only audit log viewer

Admin and Management can filter the audit log by event, user, record
type and date.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 14: Documents

**Spec:** §9.1 (documents table, categories, policy), §8.6 (upload allow-list, 10 MB, random names), §8.4 (upload, download, delete audited), §13.3 (no personal data in stored names)

**Files:**
- Create: `app/Enums/DocumentCategory.php`, `database/migrations/2026_09_28_000700_create_documents_table.php`, `app/Models/Document.php`, `app/Actions/Documents/StoreDocument.php`, `app/Actions/Documents/DeleteDocument.php`, `app/Policies/DocumentPolicy.php`, `app/Http/Controllers/DocumentController.php`, `tests/Feature/Documents/DocumentsTest.php`
- Modify: `app/Models/Building.php`, `routes/web.php`

**Interfaces:**
- Consumes: `Building` + `BuildingPolicy` (Task 9), `Audit`
- Produces: `Document`, `Building::documents(): MorphMany`, `StoreDocument::handle(User $actor, Model $documentable, UploadedFile $file, DocumentCategory $category, ?CarbonInterface $expiresOn = null): Document`, `DeleteDocument::handle(User $actor, Document $document): void`, route `documents.download`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Documents/DocumentsTest.php`:
```php
<?php

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\StoreDocument;
use App\Enums\DocumentCategory;
use App\Enums\RoleName;
use App\Models\Building;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->building = Building::factory()->create();
    $this->manager = User::factory()->create()->assignRole(RoleName::PropertyManager);
});

function upload(User $actor, Building $building, UploadedFile $file): Document
{
    return app(StoreDocument::class)->handle($actor, $building, $file, DocumentCategory::Photo);
}

test('an allowed file is stored privately under a random name', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('CPR 880101234 Ahmed.pdf', 100, 'application/pdf'));

    expect($document->path)->toMatch('#^documents/\d{4}/\d{2}/[0-9a-f-]{36}\.pdf$#')
        ->and($document->path)->not->toContain('Ahmed')
        ->and($document->original_name)->toBe('CPR 880101234 Ahmed.pdf')
        ->and($document->uploaded_by)->toBe($this->manager->id);
    Storage::disk('local')->assertExists($document->path);
    expect(Activity::query()->where('event', 'document.uploaded')->exists())->toBeTrue();
});

test('disallowed types and files over 10 MB are rejected', function () {
    expect(fn () => upload($this->manager, $this->building, UploadedFile::fake()->create('run.exe', 10, 'application/octet-stream')))
        ->toThrow(ValidationException::class);
    expect(fn () => upload($this->manager, $this->building, UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')))
        ->toThrow(ValidationException::class);
});

test('users who cannot update the building cannot upload', function () {
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);

    expect(fn () => upload($leasing, $this->building, UploadedFile::fake()->image('a.jpg')))->toThrow(AuthorizationException::class);
});

test('a user who can view the building downloads it with its original name, audited', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('lease.pdf', 10, 'application/pdf'));
    $leasing = User::factory()->create()->assignRole(RoleName::Leasing);
    $leasing->buildings()->attach($this->building->id);

    $this->actingAs($leasing)->get(route('documents.download', $document))
        ->assertOk()
        ->assertDownload('lease.pdf');

    expect(Activity::query()->where('event', 'document.downloaded')->where('causer_id', $leasing->id)->exists())->toBeTrue();
});

test('a user outside the building gets 403', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('lease.pdf', 10, 'application/pdf'));

    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('documents.download', $document))
        ->assertForbidden();
});

test('delete soft-deletes and audits', function () {
    $document = upload($this->manager, $this->building, UploadedFile::fake()->create('lease.pdf', 10, 'application/pdf'));

    app(DeleteDocument::class)->handle($this->manager, $document);

    expect(Document::find($document->id))->toBeNull()
        ->and(Document::withTrashed()->find($document->id))->not->toBeNull()
        ->and(Activity::query()->where('event', 'document.deleted')->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Documents/DocumentsTest.php`
Expected: FAIL — `Class "App\Enums\DocumentCategory" not found`.

- [ ] **Step 3: Enum, migration, model**

Create `app/Enums/DocumentCategory.php`:
```php
<?php

namespace App\Enums;

enum DocumentCategory: string
{
    case Photo = 'photo';
    case IdCopy = 'id_copy';
    case CrCopy = 'cr_copy';
    case SignedContract = 'signed_contract';
    case ChequeImage = 'cheque_image';
    case MoveOutPhoto = 'move_out_photo';
    case OwnerApproval = 'owner_approval';
    case GeneratedPdf = 'generated_pdf';
    case Other = 'other';
}
```
Create `database/migrations/2026_09_28_000700_create_documents_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('category', 30);
            $table->string('disk', 20)->default('local');
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime', 150);
            $table->unsignedInteger('size');
            $table->date('expires_on')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_category_chk CHECK (category IN ('photo', 'id_copy', 'cr_copy', 'signed_contract', 'cheque_image', 'move_out_photo', 'owner_approval', 'generated_pdf', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
```
Create `app/Models/Document.php`:
```php
<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Document extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = ['category', 'disk', 'path', 'original_name', 'mime', 'size', 'expires_on', 'uploaded_by'];

    protected function casts(): array
    {
        return ['category' => DocumentCategory::class, 'expires_on' => 'date', 'size' => 'integer'];
    }

    /** Upload, download and delete are explicit entries; this only catches later edits (e.g. expiry). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['category', 'expires_on'])->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
```
In `app/Models/Building.php` add `use Illuminate\Database\Eloquent\Relations\MorphMany;` and:
```php
    /** @return MorphMany<Document, $this> */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
```

- [ ] **Step 4: Actions, policy, controller, route**

Create `app/Actions/Documents/StoreDocument.php`:
```php
<?php

namespace App\Actions\Documents;

use App\Audit\Audit;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class StoreDocument
{
    /** Spec §8.6. */
    public const string MIMES = 'pdf,jpg,jpeg,png,webp,docx,xlsx';

    public const int MAX_KB = 10240;

    public function handle(User $actor, Model $documentable, UploadedFile $file, DocumentCategory $category, ?CarbonInterface $expiresOn = null): Document
    {
        if (! $actor->can('update', $documentable)) {
            throw new AuthorizationException;
        }

        Validator::make(['file' => $file], ['file' => ['required', 'file', 'mimes:'.self::MIMES, 'max:'.self::MAX_KB]])->validate();

        // Random name, no personal data: archive entry names are not encrypted (spec §13.3).
        $path = sprintf('documents/%s/%s.%s', now()->format('Y/m'), Str::uuid(), strtolower($file->extension()));
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));

        return DB::transaction(function () use ($actor, $documentable, $file, $category, $expiresOn, $path) {
            $document = new Document([
                'category' => $category,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
                'expires_on' => $expiresOn,
                'uploaded_by' => $actor->id,
            ]);
            $document->documentable()->associate($documentable);
            $document->save();

            Audit::log('document.uploaded', $document, properties: ['category' => $category->value], causer: $actor);

            return $document;
        });
    }
}
```
Create `app/Actions/Documents/DeleteDocument.php`:
```php
<?php

namespace App\Actions\Documents;

use App\Audit\Audit;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DeleteDocument
{
    /** Soft delete: the file stays for the audit trail and backups. */
    public function handle(User $actor, Document $document): void
    {
        if (! $actor->can('delete', $document)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $document) {
            $document->delete();

            Audit::log('document.deleted', $document, causer: $actor);
        });
    }
}
```
Create `app/Policies/DocumentPolicy.php`:
```php
<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $document->documentable !== null && $user->can('view', $document->documentable);
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->documentable !== null && $user->can('update', $document->documentable);
    }
}
```
Create `app/Http/Controllers/DocumentController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Audit\Audit;
use App\Models\Document;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function download(Document $document): StreamedResponse
    {
        Gate::authorize('view', $document);

        Audit::log('document.downloaded', $document, causer: auth()->user());

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }
}
```
In `routes/web.php` add `use App\Http\Controllers\DocumentController;` and inside the `auth` group:
```php
    Route::get('documents/{document}', [DocumentController::class, 'download'])->name('documents.download');
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan test tests/Feature/Documents/DocumentsTest.php
"$PHP" artisan test
```
Expected: 6 new tests pass; full suite passes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add private documents with audited upload and download

Files go to private storage under random names and are served only
through a policy-checked, audited download route. Buildings are the
first attachable records.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 15: Install and Vendor Support commands

**Spec:** §13.2 (`rms:install`), §8.1 (Vendor Support account, re-enabled only on the server), §6.8 (current and next year's sequences)

**Files:**
- Create: `app/Console/Commands/InstallCommand.php`, `app/Console/Commands/VendorSupportCommand.php`, `tests/Feature/Install/InstallCommandTest.php`, `tests/Feature/Install/VendorSupportCommandTest.php`

**Interfaces:**
- Consumes: `RolesAndPermissionsSeeder`, `CompanySetting`, `EnsureNumberSequences`, `RoleName`, `Audit`, `User::logoutEverywhere()`
- Produces: `rms:install {--company=} {--admin-name=} {--admin-email=} {--vendor-email=}`, `rms:vendor-support {--enable} {--disable}`

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Install/InstallCommandTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

$options = ['--company' => 'Demo Properties W.L.L.', '--admin-name' => 'Sara Admin', '--admin-email' => 'admin@demo.test', '--vendor-email' => 'support@vendor.test'];

test('it installs everything a new company needs', function () use ($options) {
    Notification::fake();

    $this->artisan('rms:install', $options)
        ->expectsOutputToContain('Vendor Support password:')
        ->expectsOutputToContain('otpauth://totp/')
        ->assertSuccessful();

    expect(CompanySetting::current()->name_en)->toBe('Demo Properties W.L.L.')
        ->and(Role::count())->toBe(6)
        ->and(DB::table('number_sequences')->count())->toBe(16);

    $admin = User::where('email', 'admin@demo.test')->firstOrFail();
    expect($admin->hasRole(RoleName::Admin))->toBeTrue();
    Notification::assertSentTo($admin, ResetPassword::class);

    $vendor = User::where('email', 'support@vendor.test')->firstOrFail();
    expect($vendor->isVendorSupport())->toBeTrue()
        ->and($vendor->two_factor_confirmed_at)->not->toBeNull()
        ->and(decrypt($vendor->two_factor_secret))->toBeString();
});

test('it refuses to run twice', function () use ($options) {
    Notification::fake();
    $this->artisan('rms:install', $options)->assertSuccessful();

    $this->artisan('rms:install', $options)->expectsOutputToContain('already installed')->assertFailed();
});

test('all options are required', function () use ($options) {
    $this->artisan('rms:install', [...$options, '--admin-email' => ''])->assertFailed();
});
```
Create `tests/Feature/Install/VendorSupportCommandTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->vendor = User::factory()->withTwoFactor()->create()->assignRole(RoleName::VendorSupport);
});

test('disable deactivates, kills sessions and audits', function () {
    DB::table('sessions')->insert(['id' => 'v1', 'user_id' => $this->vendor->id, 'payload' => '', 'last_activity' => time()]);

    $this->artisan('rms:vendor-support', ['--disable' => true])->assertSuccessful();

    expect($this->vendor->fresh()->active)->toBeFalse()
        ->and(DB::table('sessions')->where('user_id', $this->vendor->id)->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'vendor_support.disabled')->exists())->toBeTrue();
});

test('enable reactivates and audits', function () {
    $this->vendor->forceFill(['active' => false])->save();

    $this->artisan('rms:vendor-support', ['--enable' => true])->assertSuccessful();

    expect($this->vendor->fresh()->active)->toBeTrue()
        ->and(Activity::query()->where('event', 'vendor_support.enabled')->exists())->toBeTrue();
});

test('exactly one flag is required', function () {
    $this->artisan('rms:vendor-support')->assertFailed();
    $this->artisan('rms:vendor-support', ['--enable' => true, '--disable' => true])->assertFailed();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Install`
Expected: FAIL — `The command "rms:install" does not exist.`

- [ ] **Step 3: The install command**

Create `app/Console/Commands/InstallCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Actions\EnsureNumberSequences;
use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\RecoveryCode;

class InstallCommand extends Command
{
    protected $signature = 'rms:install
        {--company= : Company name (English)}
        {--admin-name= : First Admin user\'s name}
        {--admin-email= : First Admin user\'s email}
        {--vendor-email= : Vendor Support account email}';

    protected $description = 'Set up a new company install (spec §13.2). Runs once, on an empty database.';

    public function handle(EnsureNumberSequences $sequences, TwoFactorAuthenticationProvider $twoFactor): int
    {
        if (CompanySetting::query()->exists()) {
            $this->error('This install is already installed. Drop the database first to reinstall (spec §13.2).');

            return self::FAILURE;
        }

        $input = Validator::make([
            'company' => $this->option('company'),
            'admin_name' => $this->option('admin-name'),
            'admin_email' => $this->option('admin-email'),
            'vendor_email' => $this->option('vendor-email'),
        ], [
            'company' => ['required', 'string', 'max:150'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'different:vendor_email'],
            'vendor_email' => ['required', 'email'],
        ]);

        if ($input->fails()) {
            foreach ($input->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $data = $input->validated();
        $vendorPassword = Str::password(32);
        $secret = $twoFactor->generateSecretKey();

        [$admin, $vendor] = DB::transaction(function () use ($data, $sequences, $vendorPassword, $secret) {
            (new RolesAndPermissionsSeeder)->run();

            CompanySetting::query()->forceCreate(['id' => 1, 'name_en' => $data['company']]);

            $year = now('Asia/Bahrain')->year;
            $sequences($year);
            $sequences($year + 1);

            $admin = User::create(['name' => $data['admin_name'], 'email' => Str::lower($data['admin_email']), 'password' => Str::password(40)]);
            $admin->assignRole(RoleName::Admin);

            $vendor = User::create(['name' => 'Vendor Support', 'email' => Str::lower($data['vendor_email']), 'password' => $vendorPassword]);
            $vendor->forceFill([
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt(json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
                'two_factor_confirmed_at' => now(),
            ])->save();
            $vendor->assignRole(RoleName::VendorSupport);

            return [$admin, $vendor];
        });

        Password::broker()->sendResetLink(['email' => $admin->email]);

        $this->info("Installed {$data['company']}. A password link was emailed to {$admin->email}.");
        $this->warn('Store these in the vendor vault now; they are not shown again.');
        $this->line("Vendor Support password: {$vendorPassword}");
        $this->line('Vendor Support TOTP: '.$vendor->twoFactorQrCodeUrl());

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: The Vendor Support command**

Create `app/Console/Commands/VendorSupportCommand.php`:
```php
<?php

namespace App\Console\Commands;

use App\Audit\Audit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VendorSupportCommand extends Command
{
    protected $signature = 'rms:vendor-support {--enable} {--disable}';

    protected $description = 'Enable or disable the Vendor Support account (spec §8.1: re-enabling happens only here)';

    public function handle(): int
    {
        if ($this->option('enable') === $this->option('disable')) {
            $this->error('Pass exactly one of --enable or --disable.');

            return self::FAILURE;
        }

        $vendor = User::role('vendor-support')->firstOrFail();
        $enable = (bool) $this->option('enable');

        DB::transaction(function () use ($vendor, $enable) {
            $vendor->forceFill(['active' => $enable])->save();

            if (! $enable) {
                $vendor->logoutEverywhere();
            }

            Audit::log($enable ? 'vendor_support.enabled' : 'vendor_support.disabled', $vendor);
        });

        $this->info('Vendor Support '.($enable ? 'enabled.' : 'disabled.'));

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Install
"$PHP" artisan test
```
Expected: 6 new tests pass; full suite passes.

- [ ] **Step 6: Try it on the local database**

```bash
"$PHP" artisan migrate:fresh --database=migrator --force
"$PHP" artisan rms:install --company="Demo Properties W.L.L." --admin-name="Local Admin" --admin-email=admin@rms.test --vendor-email=support@rms.test
```
Expected: success output with the vendor password and otpauth URL. The Admin's reset link is written to `storage/logs/laravel.log` (local `MAIL_MAILER=log`); open it, set a password, log in at http://rms.test, and complete the forced 2FA setup.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the rms:install and rms:vendor-support commands

Install seeds roles, settings and this and next year's number sequences,
creates the first Admin (who gets a reset link) and the Vendor Support
account with a unique password and confirmed TOTP, and refuses to run
twice.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---
### Task 16: App shell — navigation, version, health, error page, trusted hosts, Sentry

**Spec:** §13.2 (`APP_VERSION` in the footer and on `/health`), §13.4 (error page, Sentry privacy), §8.6 (reset URLs from trusted hosts only), §2 (mobile)

**Files:**
- Create: `resources/views/errors/500.blade.php`, `config/sentry.php` (published), `tests/Feature/Shell/AppShellTest.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`, `config/app.php`, `bootstrap/app.php`, `.env.example`, `composer.json`

**Interfaces:**
- Consumes: routes `admin.users.index`, `admin.roles.index`, `admin.settings`, `admin.audit`; `PermissionName`
- Produces: `config('app.version')`; `GET /health` → `{"status":"up","version":...}`; Sentry configured with tag `company` = `RMS_COMPANY_CODE`

- [ ] **Step 1: Install Sentry**

```bash
$COMPOSER require sentry/sentry-laravel:^4.28
"$PHP" artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider"
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Shell/AppShellTest.php`:
```php
<?php

use App\Enums\RoleName;
use App\Models\CompanySetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    CompanySetting::factory()->create();
});

test('health reports up and the version without starting a session', function () {
    config(['app.version' => 'v1.2.3']);

    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson(['status' => 'up', 'version' => 'v1.2.3'])
        ->assertCookieMissing(config('session.cookie'));
});

test('the sidebar shows the version', function () {
    config(['app.version' => 'v9.9.9']);

    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('dashboard'))->assertOk()->assertSee('v9.9.9');
});

test('administration links appear only for their permission', function () {
    $this->actingAs(User::factory()->create()->assignRole(RoleName::Leasing))
        ->get(route('dashboard'))
        ->assertDontSee(route('admin.users.index'))
        ->assertDontSee(route('admin.audit'));

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Admin))
        ->get(route('dashboard'))
        ->assertSee(route('admin.users.index'))
        ->assertSee(route('admin.roles.index'))
        ->assertSee(route('admin.settings'))
        ->assertSee(route('admin.audit'));

    $this->actingAs(User::factory()->withTwoFactor()->create()->assignRole(RoleName::Management))
        ->get(route('dashboard'))
        ->assertSee(route('admin.audit'))
        ->assertDontSee(route('admin.users.index'));
});

test('errors show a friendly message and no details when debug is off', function () {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('secret SQL detail'));

    $this->get('/_test/boom')
        ->assertStatus(500)
        ->assertSee('Something went wrong. Please try again.')
        ->assertDontSee('secret SQL detail');
});

test('Sentry privacy settings are hard-coded', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse()
        ->and(config('sentry.max_request_body_size'))->toBe('never')
        ->and(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tags'))->toHaveKey('company');
});

test('only the APP_URL host is trusted', function () {
    // TrustHosts itself is skipped while running unit tests (shouldSpecifyTrustedHosts), so check its host list.
    config(['app.url' => 'https://rms.test']);

    $hosts = app(\Illuminate\Http\Middleware\TrustHosts::class)->hosts();

    expect($hosts)->toBe(['rms.test']);
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Shell/AppShellTest.php`
Expected: FAIL — `/health` 404, no version in the sidebar, kit error page.

- [ ] **Step 4: Version, health route, trusted hosts, Sentry in bootstrap**

In `config/app.php` add after `'name' => ...`:
```php
    // Release tag. The deploy script writes `git describe --tags` to VERSION in each release (spec §13.2).
    'version' => env('APP_VERSION') ?: (is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : 'dev'),
```
Replace `bootstrap/app.php` with:
```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // Outside the web group: no session row per uptime ping.
            Route::get('/health', function () {
                DB::select('select 1');

                return response()->json(['status' => 'up', 'version' => config('app.version')]);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Reset links and redirects are built only for our own host (spec §8.6).
        $middleware->trustHosts(at: fn () => [parse_url((string) config('app.url'), PHP_URL_HOST)], subdomains: false);

        // Every web request, including Livewire's update endpoint and Fortify's routes.
        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\EnsureTwoFactorIsConfirmed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
```
`TrustHosts` rejects foreign hosts in production and staging; Laravel skips it in `local` and while running tests (`shouldSpecifyTrustedHosts()`), so the test checks the host list it would enforce. Check it for real on staging (Task 20): `curl -H 'Host: evil.example' https://<staging>/login` must return 400.

- [ ] **Step 5: Sentry config**

In `config/sentry.php` set (replace the matching keys; add the ones not present):
```php
    'release' => env('APP_VERSION') ?: (is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : null),

    // Hard-coded, never from env: personal data, request bodies and SQL bindings must not leave the server (spec §13.4).
    'send_default_pii' => false,
    'max_request_body_size' => 'never',

    // One Sentry project for every install; each event says which company it came from.
    'tags' => ['company' => env('RMS_COMPANY_CODE', 'unknown')],

    'ignore_transactions' => ['/health'],
```
and inside the existing `'breadcrumbs' => [...]` and `'tracing' => [...]` arrays set `'sql_bindings' => false,` (replace their `env(...)` values). Add to `.env.example`:
```
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0
RMS_COMPANY_CODE=
```
The DSN comes from the product owner's Sentry account; with it empty, Sentry sends nothing.

- [ ] **Step 6: Error page and navigation**

Create `resources/views/errors/500.blade.php`:
```blade
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        <style>
            body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: #fafafa; color: #27272a; }
            main { text-align: center; padding: 16px; }
        </style>
    </head>
    <body>
        <main>
            <h1>Something went wrong. Please try again.</h1>
            <p><a href="{{ url('/') }}">Back to the start page</a></p>
        </main>
    </body>
</html>
```
In `resources/views/layouts/app/sidebar.blade.php`, replace the first `<flux:sidebar.nav> ... </flux:sidebar.nav>` block (the one with the "Platform" group) with:
```blade
            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @canany(['users.manage', 'roles.manage', 'settings.manage', 'audit.view'])
                    <flux:sidebar.group :heading="__('Administration')" class="grid">
                        @can('users.manage')
                            <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.*')" wire:navigate>{{ __('Users') }}</flux:sidebar.item>
                        @endcan
                        @can('roles.manage')
                            <flux:sidebar.item icon="key" :href="route('admin.roles.index')" :current="request()->routeIs('admin.roles.*')" wire:navigate>{{ __('Roles') }}</flux:sidebar.item>
                        @endcan
                        @can('settings.manage')
                            <flux:sidebar.item icon="building-office" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>{{ __('Company settings') }}</flux:sidebar.item>
                        @endcan
                        @can('audit.view')
                            <flux:sidebar.item icon="clipboard-document-list" :href="route('admin.audit')" :current="request()->routeIs('admin.audit')" wire:navigate>{{ __('Audit log') }}</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
            </flux:sidebar.nav>
```
Replace the second `<flux:sidebar.nav>` block (the kit's Repository and Documentation links) with:
```blade
            <flux:text size="sm" class="px-2">{{ config('app.version') }}</flux:text>
```

- [ ] **Step 7: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Shell/AppShellTest.php
"$PHP" artisan test
```
Expected: 6 new tests pass; full suite passes. Check http://rms.test at 375 px: the sidebar collapses behind the menu button.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add navigation, version footer, health check, error page and Sentry

Administration links follow permissions; /health reports the release
without creating a session; production errors show a friendly message;
foreign hosts are rejected; Sentry never receives personal data, request
bodies or SQL bindings.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 17: PDF renderer and Arabic spike

**Spec:** §9.2 (mPDF, IBM Plex Sans Arabic with OpenType layout, one row per paragraph, escaping, local images only, spike pass criteria), §9.4 (QR code)

**Files:**
- Create: `app/Pdf/PdfRenderer.php`, `resources/fonts/IBMPlexSansArabic-Regular.ttf`, `resources/fonts/IBMPlexSansArabic-Bold.ttf`, `resources/fonts/OFL-IBMPlexSansArabic.txt`, `resources/views/pdf/spike.blade.php`, `tests/Feature/Pdf/PdfRendererTest.php`
- Modify: `composer.json`

**Interfaces:**
- Produces: `App\Pdf\PdfRenderer::render(string $view, array $data = [], array $chrome = []): string` (`$chrome` keys: `header`, `footer`, `watermark`), `PdfRenderer::make(): Mpdf`; font family `arabic` usable in PDF views

- [ ] **Step 1: Install mPDF and the font**

```bash
$COMPOSER require mpdf/mpdf:^8.3 mpdf/qrcode:^1.2
mkdir -p resources/fonts && cd resources/fonts
curl -sSLO https://github.com/google/fonts/raw/main/ofl/ibmplexsansarabic/IBMPlexSansArabic-Regular.ttf
curl -sSLO https://github.com/google/fonts/raw/main/ofl/ibmplexsansarabic/IBMPlexSansArabic-Bold.ttf
curl -sSL -o OFL-IBMPlexSansArabic.txt https://github.com/google/fonts/raw/main/ofl/ibmplexsansarabic/OFL.txt
cd ../..
ls -l resources/fonts
```
Expected: two `.ttf` files of a few hundred KB each and the licence text. (Do not use Noto Naskh or Amiri: mPDF 8.3.1 throws "GPOS Lookup Type 5, Format 3 not supported" on them.)

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Pdf/PdfRendererTest.php`:
```php
<?php

use App\Pdf\PdfRenderer;
use Illuminate\Support\Facades\Storage;

function spikeData(): array
{
    $clauses = [
        [['This Agreement is made on 28/09/2026 between Example Properties W.L.L. (the Landlord) and the Tenant named in Schedule 1.'],
         ['حُرِّر هذا العقد بتاريخ 28/09/2026 بين شركة مثال للعقارات ذ.م.م (المؤجر) والمستأجر المذكور في الجدول رقم 1.']],
        [['The lease term starts on 01/10/2026 and ends on 30/09/2027 (12 months).'],
         ['تبدأ مدة الإيجار في 01/10/2026 وتنتهي في 30/09/2027 (12 شهراً).']],
        [['The monthly rent is BHD 350.500, payable in advance on the 1st day of each month.'],
         ['الإيجار الشهري 350.500 د.ب (BHD 350.500)، يُدفع مقدماً في اليوم الأول من كل شهر.']],
        [['The Tenant shall pay a security deposit of BHD 701.000 on signing, refundable within 30 days after move-out.'],
         ['يدفع المستأجر تأميناً قدره 701.000 دينار بحريني عند التوقيع، ويُرد خلال 30 يوماً من تاريخ الإخلاء.']],
        [['Units: Flat 12A (Building 7, Road 2803, Block 428, Seef) and Parking P-15, as listed on invoice INV-2026-000042.'],
         ['الوحدات: الشقة 12A (المبنى 7، الطريق 2803، المجمع 428، السيف) وموقف السيارة P-15، كما في الفاتورة رقم INV-2026-000042.']],
        [['Either party may terminate by giving 60 days\' written notice, effective no earlier than 2027-03-31.'],
         ['يجوز لأي من الطرفين إنهاء العقد بإخطار كتابي مدته 60 يوماً، على ألا يسري الإنهاء قبل 2027-03-31.']],
        [['VAT at 10% is charged where applicable; the rent of 350.500 excludes VAT.'],
         ['تُفرض ضريبة القيمة المضافة بنسبة 10% حيثما ينطبق ذلك، والإيجار البالغ 350.500 لا يشمل الضريبة.']],
    ];

    // A multi-paragraph clause (one row per paragraph) that pushes the document onto page 2.
    $en = [];
    $ar = [];
    for ($i = 1; $i <= 9; $i++) {
        $en[] = "({$i}) The Tenant shall keep the unit clean and in good repair, report any defect within 3 days, and shall not make alterations without the Landlord's written consent; repairs above BHD 25.000 need approval.";
        $ar[] = "({$i}) يلتزم المستأجر بالمحافظة على نظافة الوحدة وصيانتها، والإبلاغ عن أي عطل خلال 3 أيام، ولا يجوز له إجراء أي تعديلات دون موافقة المؤجر الكتابية، وتحتاج الإصلاحات التي تزيد على 25.000 د.ب إلى موافقة.";
    }
    $clauses[] = [$en, $ar];
    $clauses[] = [['This Agreement is governed by the laws of the Kingdom of Bahrain.'], ['يخضع هذا العقد لقوانين مملكة البحرين.']];

    return [
        'agreementNo' => 'AGR-2026-000001',
        'verifyUrl' => 'https://rms.example.bh/v/'.str_repeat('Ab3x', 8),
        'units' => [
            ['unit' => 'Flat 12A', 'building' => 'Building 7, Seef', 'from' => '01/10/2026', 'to' => '30/09/2027', 'rent' => '350.500'],
            ['unit' => 'Parking P-15', 'building' => 'Building 7, Seef', 'from' => '01/10/2026', 'to' => '30/09/2027', 'rent' => '15.000'],
        ],
        'clauses' => array_map(fn ($pair) => ['en' => $pair[0], 'ar' => $pair[1]], $clauses),
    ];
}

function spikeChrome(): array
{
    return [
        'header' => '<table width="100%" style="font-size:8pt;border-bottom:0.2mm solid #999"><tr>'
            .'<td>Example Properties W.L.L. — AGR-2026-000001</td>'
            .'<td style="text-align:right;font-family: arabic" dir="rtl">شركة مثال للعقارات ذ.م.م</td></tr></table>',
        'footer' => '<table width="100%" style="font-size:8pt"><tr>'
            .'<td>Page {PAGENO} of {nbpg}</td>'
            .'<td style="text-align:right;font-family: arabic" dir="rtl">صفحة {PAGENO} من {nbpg}</td></tr></table>',
        'watermark' => 'DRAFT',
    ];
}

it('renders the two-page bilingual contract spike quickly', function () {
    $start = hrtime(true);

    $pdf = app(PdfRenderer::class)->render('pdf.spike', spikeData(), spikeChrome());

    $ms = (hrtime(true) - $start) / 1e6;
    $pages = preg_match_all('#/Type /Page\b(?!s)#', $pdf);

    Storage::disk('local')->put('pdf-spike/spike.pdf', $pdf); // for the manual check in Step 6

    expect($pdf)->toStartWith('%PDF-')
        ->and($pages)->toBe(2)
        ->and($ms / $pages)->toBeLessThan(3000); // spec §9.2: under 3 s per page
});

it('refuses remote images referenced from HTML', function () {
    $mpdf = app(PdfRenderer::class)->make();
    $mpdf->debug = true; // surface the refusal instead of silently drawing a broken image

    expect(fn () => $mpdf->WriteHTML('<img src="https://example.com/logo.png">'))
        ->toThrow(Mpdf\MpdfException::class, 'invalid stream');
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `"$PHP" artisan test tests/Feature/Pdf/PdfRendererTest.php`
Expected: FAIL — `Class "App\Pdf\PdfRenderer" not found`.

- [ ] **Step 4: The renderer**

Create `app/Pdf/PdfRenderer.php`:
```php
<?php

namespace App\Pdf;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** Blade → HTML → PDF with mPDF (spec §9.2). Returns the PDF bytes; the caller stores them privately. */
class PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array{header?: string, footer?: string, watermark?: string|null}  $chrome
     */
    public function render(string $view, array $data = [], array $chrome = []): string
    {
        $mpdf = $this->make();

        if (isset($chrome['header'])) {
            $mpdf->SetHTMLHeader($chrome['header']);
        }
        if (isset($chrome['footer'])) {
            $mpdf->SetHTMLFooter($chrome['footer']); // may contain {PAGENO} and {nbpg}
        }
        if (! empty($chrome['watermark'])) {
            $mpdf->SetWatermarkText($chrome['watermark'], 0.08);
            $mpdf->showWatermarkText = true;
        }

        $mpdf->WriteHTML(view($view, $data)->render());

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    public function make(): Mpdf
    {
        $tempDir = storage_path('app/mpdf'); // font cache; must be writable; outside app/private so it isn't backed up
        File::ensureDirectoryExists($tempDir);

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 28,
            'margin_bottom' => 22,
            'margin_header' => 8,
            'margin_footer' => 8,
            'tempDir' => $tempDir,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts')],
            'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + [
                'arabic' => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,   // without OpenType layout Arabic letters don't join
                    'useKashida' => 75, // kashida for justified Arabic
                ],
            ],
            'default_font' => 'dejavusans',
            // 1 = never shrink for width (0 is silently clamped to 1). A single row taller than a page
            // still scales the WHOLE table, hence one row per clause paragraph (spec §9.2).
            'shrink_tables_to_fit' => 1,
            'whitelistStreamWrappers' => ['file'], // no http(s) fetches from HTML
        ]);
    }
}
```

- [ ] **Step 5: The spike view**

Create `resources/views/pdf/spike.blade.php` (a throwaway fixture for this task; the real contract template arrives in M2):
```blade
<html>
<head>
<style>
    body { font-family: dejavusans; font-size: 9.5pt; }
    .ar { font-family: arabic; font-size: 11pt; direction: rtl; text-align: justify; }
    h1 { font-size: 13pt; text-align: center; margin: 0 0 2mm; }
    table.clauses { width: 100%; border-collapse: collapse; }
    table.clauses td { width: 50%; vertical-align: top; padding: 1.5mm 2mm; border-bottom: 0.2mm solid #999; }
    table.units { width: 100%; border-collapse: collapse; margin: 3mm 0; }
    table.units th, table.units td { border: 0.2mm solid #666; padding: 1mm 2mm; }
    .num { font-weight: bold; }
</style>
</head>
<body>
<h1>Lease Agreement {{ $agreementNo }} &nbsp;|&nbsp; <span class="ar">عقد إيجار رقم {{ $agreementNo }}</span></h1>

<table class="units">
    <tr><th>Unit</th><th>Building</th><th>From</th><th>To</th><th>Monthly rent (BHD)</th></tr>
    @foreach ($units as $unit)
        <tr><td>{{ $unit['unit'] }}</td><td>{{ $unit['building'] }}</td><td>{{ $unit['from'] }}</td><td>{{ $unit['to'] }}</td><td style="text-align:right">{{ $unit['rent'] }}</td></tr>
    @endforeach
</table>

{{-- One row per clause paragraph (spec §9.2): mPDF never splits a row, and a tall row shrinks the whole table. --}}
<table class="clauses">
    @foreach ($clauses as $n => $clause)
        @foreach ($clause['en'] as $i => $paragraph)
            <tr>
                <td>@if ($i === 0)<span class="num">{{ $n + 1 }}.</span> @endif{{ $paragraph }}</td>
                <td class="ar" dir="rtl" lang="ar">@if ($i === 0)<span class="num">{{ $n + 1 }}.</span> @endif{{ $clause['ar'][$i] ?? '' }}</td>
            </tr>
        @endforeach
    @endforeach
</table>

<table style="width:100%; margin-top:6mm">
    <tr>
        <td style="width:70%; vertical-align:bottom">Scan to verify this agreement:<br>{{ $verifyUrl }}</td>
        <td style="width:30%; text-align:right">
            {{-- Vector QR drawn by mpdf/qrcode: no image fetch. --}}
            <barcode code="{{ $verifyUrl }}" type="QR" size="0.9" error="M" disableborder="1" />
        </td>
    </tr>
</table>
</body>
</html>
```

- [ ] **Step 6: Run the tests, then check the PDF by eye**

```bash
"$PHP" artisan test tests/Feature/Pdf/PdfRendererTest.php
```
Expected: 2 tests pass. Then open `storage/app/private/pdf-spike/spike.pdf` in a browser and confirm every item:
1. Arabic letters are joined (connected forms, lam-alef ligature), not isolated.
2. Arabic lines run right to left.
3. Dates, amounts and references inside Arabic read in the correct order: 28/09/2026, 01/10/2026, 2027-03-31, 350.500, 701.000, 10%, INV-2026-000042, 12A, P-15.
4. The header shows the Arabic company name; footers read "Page 1 of 2 / صفحة 1 من 2" and "Page 2 of 2 / صفحة 2 من 2".
5. The DRAFT watermark is on both pages.
6. The QR code on page 2 scans (phone camera) to `https://rms.example.bh/v/Ab3xAb3x…`.
7. The clauses table is not scaled down.

If any item fails, stop and report it: the spec's fallback is `spatie/laravel-pdf` with the `chrome` or `weasyprint` driver.

- [ ] **Step 7: Commit**

```bash
"$PHP" artisan test
git add -A
git commit -q -F - <<'EOF'
Add the mPDF renderer with Arabic support and the contract spike

IBM Plex Sans Arabic renders joined, right-to-left text with OpenType
layout; the two-page bilingual spike passes the spec 9.2 checks (checked
by eye: joining, RTL, number order, page numbers, watermark, QR).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 18: Backups and scheduled jobs

**Spec:** §13.3 (nightly encrypted backup via the migrator connection, retention, restore check), §12 (schedule, heartbeats, `withoutOverlapping(120)`)

**Files:**
- Create: `config/backup.php`, `config/backup-restore.php` (published), `app/Backup/RestoredBackupIsSane.php`, `tests/Feature/Backup/BackupConfigTest.php`, `tests/Feature/Backup/ScheduleTest.php`
- Modify: `composer.json`, `config/database.php`, `config/filesystems.php`, `config/services.php`, `routes/console.php`, `.env.example`

**Interfaces:**
- Consumes: `migrator` connection (Task 1), Task 8 schedule and `services.forge.heartbeats`
- Produces: disks `backups`, `backups-s3`; connection `restore`; scheduled `backup:clean` 03:15, `backup:run` 03:30, `backup:monitor` 07:00; heartbeat keys `backup_clean`, `backup_run`, `backup_monitor`

- [ ] **Step 1: Install**

```bash
$COMPOSER require spatie/laravel-backup:^10 wnx/laravel-backup-restore:^1.9 league/flysystem-aws-s3-v3:^3
"$PHP" artisan vendor:publish --tag=backup-config --tag=backup-restore-config
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/Backup/BackupConfigTest.php`:
```php
<?php

test('backups dump through the migrator connection so triggers are included', function () {
    expect(config('backup.backup.source.databases'))->toBe(['migrator'])
        ->and(config('database.connections.migrator.dump.mysql_gtid_purged'))->toBe('OFF')
        ->and(config('database.connections.migrator.dump'))->toContain('use_single_transaction', 'include_routines');
});

test('private files are included and the restore scratch folder is not', function () {
    $sep = DIRECTORY_SEPARATOR;

    expect(config('backup.backup.source.files.include'))->toBe([storage_path('app'.$sep.'private')])
        ->and(config('backup.backup.source.files.exclude'))->toContain(storage_path('app'.$sep.'private'.$sep.'backup-restore-temp'));
});

test('archives are AES-256 encrypted with the per-install password', function () {
    expect(config('backup.backup.encryption'))->toBe('aes256')
        ->and(config('backup.backup.password'))->toBe(env('BACKUP_ARCHIVE_PASSWORD'));
});

test('retention keeps 30 days of dailies and 12 monthly, with no size-based deletion', function () {
    $strategy = config('backup.cleanup.default_strategy');

    expect($strategy['keep_all_backups_for_days'] + $strategy['keep_daily_backups_for_days'])->toBe(30)
        ->and($strategy['keep_weekly_backups_for_weeks'])->toBe(0)
        ->and($strategy['keep_monthly_backups_for_months'])->toBe(12)
        ->and($strategy['delete_oldest_backups_when_using_more_megabytes_than'])->toBeNull()
        ->and(array_keys(config('backup.monitor_backups.0.health_checks')))->toBe([\Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class]);
});
```
Create `tests/Feature/Backup/ScheduleTest.php`:
```php
<?php

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(string $command): \Illuminate\Console\Scheduling\Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, $command))
        ?? throw new RuntimeException("{$command} is not scheduled");
}

test('the backup and numbering jobs are scheduled in Bahrain time without overlap', function (string $command, string $cron) {
    $event = scheduledEvent($command);

    expect($event->expression)->toBe($cron)
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and(config('app.timezone'))->toBe('Asia/Bahrain');
})->with([
    ['backup:clean', '15 3 * * *'],
    ['backup:run', '30 3 * * *'],
    ['backup:monitor', '0 7 * * *'],
    ['rms:number-sequences', '30 4 1 12 *'],
]);

test('a successful run pings its heartbeat exactly once', function () {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200)]));
    $stack->push(Middleware::history($history));
    app()->instance(ClientInterface::class, new Client(['handler' => $stack]));

    config(['services.forge.heartbeats.number_sequences' => 'https://forge.test/heartbeat/abc']);
    require base_path('routes/console.php'); // re-register now that the URL is configured

    $event = collect(app(Schedule::class)->events())->last(fn ($e) => str_contains((string) $e->command, 'rms:number-sequences'));
    $event->run(app());

    expect($event->exitCode)->toBe(0)
        ->and($history)->toHaveCount(1)
        ->and((string) $history[0]['request']->getUri())->toBe('https://forge.test/heartbeat/abc');
});
```

- [ ] **Step 3: Run them to verify they fail**

Run: `"$PHP" artisan test tests/Feature/Backup`
Expected: FAIL — `source.databases` is `['mysql']` and the backup jobs are not scheduled.

- [ ] **Step 4: Dump settings, restore connection, disks**

In `config/database.php`, inside the `'migrator' => [...]` connection add:
```php
            // Backups dump as the migrator: only a user with TRIGGER gets the triggers into the dump (spec §8.5).
            'dump' => [
                // Windows: C:/Users/<you>/.mysql/mysql-26.7.0-winx64/bin   Ubuntu (Oracle APT): /usr/bin
                'dump_binary_path' => env('DB_DUMP_BINARY_PATH', ''),
                'use_single_transaction',
                'skip_lock_tables',
                'use_quick',
                'include_routines',
                'mysql_gtid_purged' => 'OFF', // a GTID_PURGED line makes the dump unrestorable on another GTID server
                'add_extra_option' => '--no-tablespaces',
                'timeout' => 60 * 30,
            ],
```
and add a new connection after it:
```php
        // Restore-check target only (spec §13.3). Never point it at the live database.
        'restore' => [
            'driver' => 'mysql',
            'host' => env('RESTORE_DB_HOST', '127.0.0.1'),
            'port' => env('RESTORE_DB_PORT', '3306'),
            'database' => env('RESTORE_DB_DATABASE', 'rms_restore_check'),
            'username' => env('RESTORE_DB_USERNAME', 'root'),
            'password' => env('RESTORE_DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'dump' => ['dump_binary_path' => env('DB_DUMP_BINARY_PATH', '')], // the restore package reads this to find `mysql`
        ],
```
In `config/filesystems.php`, inside `'disks'` add:
```php
        'backups' => [
            'driver' => 'local',
            'root' => storage_path('app/backups'),
            'throw' => true,
        ],

        'backups-s3' => [
            'driver' => 's3',
            'key' => env('BACKUP_S3_KEY'),
            'secret' => env('BACKUP_S3_SECRET'),
            'region' => env('BACKUP_S3_REGION', 'us-east-1'),
            'bucket' => env('BACKUP_S3_BUCKET'),
            'endpoint' => env('BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('BACKUP_S3_PATH_STYLE', true),
            'throw' => true,
            'report' => true,
        ],
```

- [ ] **Step 5: `config/backup.php`**

Edit the published `config/backup.php` so these keys have exactly these values (leave the rest as published):
```php
    'backup' => [
        'name' => env('APP_NAME', 'laravel-backup'),
        'source' => [
            'files' => [
                // DIRECTORY_SEPARATOR, not '/': on Windows a '/' makes zip entries absolute and restores reject them.
                'include' => [storage_path('app'.DIRECTORY_SEPARATOR.'private')],
                'exclude' => [storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'backup-restore-temp')],
                'follow_links' => false,
                'ignore_unreadable_directories' => false,
                'relative_path' => storage_path('app'),
            ],
            'databases' => ['migrator'],
        ],
        'destination' => [
            'compression_method' => ZipArchive::CM_DEFAULT,
            'compression_level' => 9,
            'filename_prefix' => '',
            'disks' => explode(',', env('BACKUP_DISKS', 'backups')), // production: backups-s3
            'continue_on_failure' => false,
        ],
        'temporary_directory' => storage_path('app/backup-temp'),
        'password' => env('BACKUP_ARCHIVE_PASSWORD'), // per install, kept in the vendor vault
        'encryption' => 'aes256',
        'verify_backup' => true,
        'tries' => 1,
        'retry_delay' => 0,
    ],
```
```php
    'notifications' => [
        // keep the published 'notifications' => [...] class list (all mail)
        'mail' => [
            'to' => env('BACKUP_NOTIFY_EMAIL', 'ops@example.com'),
            'from' => ['address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'), 'name' => env('MAIL_FROM_NAME', 'Rental Management')],
        ],
    ],
```
```php
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'laravel-backup'),
            'disks' => explode(',', env('BACKUP_DISKS', 'backups')),
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
            ],
        ],
    ],
```
```php
    'cleanup' => [
        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,
        'default_strategy' => [
            'keep_all_backups_for_days' => 7,
            'keep_daily_backups_for_days' => 23,   // 7 + 23 = 30 days of dailies (spec §13.3)
            'keep_weekly_backups_for_weeks' => 0,
            'keep_monthly_backups_for_months' => 12,
            'keep_yearly_backups_for_years' => 0,
            'delete_oldest_backups_when_using_more_megabytes_than' => null, // no size-based deletion
        ],
        'tries' => 1,
        'retry_delay' => 0,
    ],
```
Add to `.env.example`:
```
BACKUP_DISKS=backups
BACKUP_ARCHIVE_PASSWORD=
BACKUP_NOTIFY_EMAIL=
DB_DUMP_BINARY_PATH=
BACKUP_S3_KEY=
BACKUP_S3_SECRET=
BACKUP_S3_REGION=
BACKUP_S3_BUCKET=
BACKUP_S3_ENDPOINT=
HEARTBEAT_BACKUP_CLEAN=
HEARTBEAT_BACKUP_RUN=
HEARTBEAT_BACKUP_MONITOR=
HEARTBEAT_NUMBER_SEQUENCES=
```

- [ ] **Step 6: Restore health check**

Create `app/Backup/RestoredBackupIsSane.php`:
```php
<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Wnx\LaravelBackupRestore\HealthChecks\HealthCheck;
use Wnx\LaravelBackupRestore\HealthChecks\Result;
use Wnx\LaravelBackupRestore\PendingRestore;

/** Weekly restore test (spec §13.3): sanity counts plus the archive's private files. Run backup:restore with --keep. */
class RestoredBackupIsSane extends HealthCheck
{
    /** table => minimum rows expected after a restore */
    private const array MIN_ROWS = ['users' => 1, 'company_settings' => 1, 'roles' => 6];

    public function run(PendingRestore $pendingRestore): Result
    {
        $result = Result::make($this);
        $db = DB::connection($pendingRestore->connection);

        foreach (self::MIN_ROWS as $table => $min) {
            $count = $db->table($table)->count();
            if ($count < $min) {
                return $result->failed("Restored table {$table} has {$count} rows, expected at least {$min}.");
            }
        }

        $extracted = $pendingRestore->getAbsolutePathToLocalDecompressedBackup().DIRECTORY_SEPARATOR.'private';
        if (! File::isDirectory($extracted) || count(File::allFiles($extracted, true)) === 0) {
            return $result->failed("The archive has no private files at {$extracted} (was --keep passed?).");
        }

        // ponytail: also check each documents.path exists in $extracted once documents are in regular use.
        File::deleteDirectory($pendingRestore->getAbsolutePathToLocalDecompressedBackup());

        return $result->ok();
    }
}
```
In `config/backup-restore.php` set:
```php
    'health-checks' => [
        \Wnx\LaravelBackupRestore\HealthChecks\Checks\DatabaseHasTables::class,
        \App\Backup\RestoredBackupIsSane::class,
    ],
```

- [ ] **Step 7: Schedule and heartbeats**

In `config/services.php` replace the `'forge'` entry with:
```php
    'forge' => [
        'heartbeats' => [
            'backup_clean' => env('HEARTBEAT_BACKUP_CLEAN'),
            'backup_run' => env('HEARTBEAT_BACKUP_RUN'),
            'backup_monitor' => env('HEARTBEAT_BACKUP_MONITOR'),
            'number_sequences' => env('HEARTBEAT_NUMBER_SEQUENCES'),
        ],
    ],
```
Append to `routes/console.php`:
```php
Schedule::command('backup:clean')->dailyAt('03:15')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.backup_clean')), (string) $url);

Schedule::command('backup:run')->dailyAt('03:30')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.backup_run')), (string) $url);

Schedule::command('backup:monitor')->dailyAt('07:00')->withoutOverlapping(120)
    ->pingOnSuccessIf(filled($url = config('services.forge.heartbeats.backup_monitor')), (string) $url);
```

- [ ] **Step 8: Run the tests**

```bash
"$PHP" artisan test tests/Feature/Backup
"$PHP" artisan test
```
Expected: 9 new tests pass; full suite passes. If the dataset's `$event->withoutOverlapping` property is named differently, check `grep -n "public \$withoutOverlapping" vendor/laravel/framework/src/Illuminate/Console/Scheduling/Event.php`.

- [ ] **Step 9: Run a real backup and restore locally**

```bash
setenv() { local f="$1" k="$2" v="$3"; sed -i "/^#\? *${k}=/d" "$f"; printf '%s=%s\n' "$k" "$v" >> "$f"; }
setenv .env DB_DUMP_BINARY_PATH "C:/Users/ababy/.mysql/mysql-26.7.0-winx64/bin"
setenv .env BACKUP_ARCHIVE_PASSWORD "$(openssl rand -hex 24)"
"$MYSQL" -u root -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS rms_restore_check"
"$PHP" artisan tinker --execute="Storage::disk('local')->put('documents/check/probe.txt', 'backup probe');"
"$PHP" artisan backup:run --disable-notifications
"$PHP" artisan backup:restore --disk=backups --connection=restore --reset --keep --no-interaction
"$MYSQL" -u root -h 127.0.0.1 -N -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='rms_restore_check'"
"$MYSQL" -u root -h 127.0.0.1 -e "DROP DATABASE rms_restore_check"
```
Expected: `backup:run` reports "Backup completed!"; `backup:restore` ends with "All health checks passed."; the trigger count is `2` (the activity_log triggers came through the dump). Requires the local database to have been installed with `rms:install` (Task 15, Step 6). Local Windows backups are for this check only — never ship a Windows-made archive (zip entry names use backslashes).

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add encrypted nightly backups with a restore check

Backups dump through the migrator connection so triggers are kept, zip
the private files with AES-256, keep 30 dailies and 12 monthlies, and
ping Forge heartbeats. A local backup restored with every health check
passing and both audit-log triggers present.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

---

### Task 19: CI workflow

**Spec:** §14 (CI on every pull request: Pest on MySQL 26.7 as a non-root user with binary logs on, Pint, Larastan, `composer audit`)

**Files:**
- Create: `.github/workflows/ci.yml`, `phpstan-baseline.neon`
- Modify: `phpstan.neon`
- Delete: `.github/workflows/tests.yml`

**Interfaces:**
- Consumes: env names `DB_USERNAME`, `DB_PASSWORD`, `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD` (Task 1)
- Produces: CI job `ci`

- [ ] **Step 1: The workflow**

```bash
git rm -q .github/workflows/tests.yml
```
Create `.github/workflows/ci.yml`:
```yaml
name: ci

on:
  push:
    branches:
      - main
  pull_request:

permissions:
  contents: read

jobs:
  ci:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:26.7.0
        # Binary logging is on by default; a non-SUPER user can create triggers only with this (spec §8.5, §14).
        command: --log-bin-trust-function-creators=1
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: rms_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -uroot -proot"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=30

    env:
      DB_CONNECTION: mysql
      DB_HOST: 127.0.0.1
      DB_PORT: 3306
      DB_DATABASE: rms_test
      # The app, and therefore every test, runs as the DML-only user, as in production.
      DB_USERNAME: rms_app
      DB_PASSWORD: app-secret
      DB_MIGRATOR_USERNAME: rms_migrate
      DB_MIGRATOR_PASSWORD: migrate-secret

    steps:
      - name: Checkout code
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false

      - name: Setup PHP
        uses: shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2
        with:
          php-version: '8.5'
          extensions: mbstring, pdo_mysql, intl, gd, zip, bcmath
          tools: composer:v2
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Create database users
        run: |
          docker exec -i ${{ job.services.mysql.id }} mysql -uroot -proot <<'SQL'
          CREATE USER 'rms_migrate'@'%' IDENTIFIED BY 'migrate-secret';
          CREATE USER 'rms_app'@'%' IDENTIFIED BY 'app-secret';
          GRANT ALL PRIVILEGES ON rms_test.* TO 'rms_migrate'@'%';
          GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON rms_test.* TO 'rms_app'@'%';
          SQL

      - name: Assert binary log and trigger settings
        run: |
          docker exec ${{ job.services.mysql.id }} mysql -uroot -proot -N -e \
            "SELECT @@version, @@log_bin, @@log_bin_trust_function_creators" | tee /dev/stderr \
            | grep -qE $'\t1\t1$'

      - name: Migrate as migrator (the deploy command)
        run: php artisan migrate --database=migrator --force

      - name: Tests
        run: php artisan test

      - name: Pint
        run: vendor/bin/pint --test

      - name: Larastan
        run: vendor/bin/phpstan analyse --no-progress --memory-limit=1G

      - name: Composer audit
        run: composer audit --locked
```
The CI passwords are throwaway values for a disposable container, not secrets.

- [ ] **Step 2: Larastan level 8 with a baseline**

Replace `phpstan.neon` with:
```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/nesbot/carbon/extension.neon
    - phpstan-baseline.neon

parameters:
    paths:
        - app/
        - bootstrap/app.php
        - config/
        - database/
        - routes/

    level: 8
```
```bash
echo "parameters: []" > phpstan-baseline.neon
"$PHP" vendor/bin/phpstan analyse --generate-baseline --memory-limit=1G
```
Open `phpstan-baseline.neon`: every entry must be in a starter-kit file (`app/Livewire/Settings/*`, nullable `Auth::user()` calls). If any entry points at code written in this plan, fix that code instead of baselining it, and regenerate.

- [ ] **Step 3: Run the same checks locally**

```bash
"$PHP" -r "require 'vendor/autoload.php'; Symfony\Component\Yaml\Yaml::parseFile('.github/workflows/ci.yml'); echo 'yaml ok', PHP_EOL;"
"$PHP" artisan migrate:fresh --database=migrator --force --env=testing
"$PHP" artisan test
"$PHP" vendor/bin/pint --test
"$PHP" vendor/bin/phpstan analyse --no-progress --memory-limit=1G
$COMPOSER audit --locked
```
Expected: `yaml ok`, all tests pass, Pint clean, `[OK] No errors`, no advisories.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add the CI workflow on MySQL 26.7

Every push and pull request migrates as a non-root user with binary logs
on, runs the tests as the DML-only app user, and checks Pint, Larastan
level 8 and composer audit.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 5: STOP AND ASK — GitHub repository**

Ask the product owner which GitHub repository (and organisation) to use. Do not create or push to a repository yourself. Once they have created it and given the URL: `git remote add origin <url> && git push -u origin main`, then confirm the `ci` workflow passes on GitHub.

---

### Task 20: Deploy scripts and staging runbook

**Spec:** §13.1 (Bahrain VPS, Forge Business, MySQL 26.7 via Oracle APT, zero-downtime with shared `storage`, `release` branch), §13.2 (install, releases, staging with demo data only, go-live sequence), §13.3 (backups, restore-check server), §13.4 (monitoring)

**Files:**
- Create: `deploy/provision-mysql.sh`, `deploy/forge-deploy.sh`, `deploy/README.md`

**Interfaces:**
- Consumes: env names from Tasks 1, 16, 18; commands `rms:install`, `permission:cache-reset`, `migrate --database=migrator`
- Produces: the ops runbook

- [ ] **Step 1: MySQL provisioning script**

Create `deploy/provision-mysql.sh`:
```bash
#!/usr/bin/env bash
# Installs MySQL 26.7 (Innovation track) from Oracle's APT repository and creates the RMS database and users.
# Run as root on a fresh Ubuntu 26.04 server BEFORE adding the site in Forge (Forge offers no MySQL 26.x).
# Usage: RMS_APP_PASSWORD=... RMS_MIGRATE_PASSWORD=... [RMS_DB=rms] ./provision-mysql.sh
set -euo pipefail

: "${RMS_APP_PASSWORD:?set RMS_APP_PASSWORD}"
: "${RMS_MIGRATE_PASSWORD:?set RMS_MIGRATE_PASSWORD}"
RMS_DB="${RMS_DB:-rms}"
CODENAME="$(. /etc/os-release && echo "$VERSION_CODENAME")"

# Oracle's repository must publish this Ubuntu release, or apt would silently install Ubuntu's own MySQL.
if ! curl -fsSL "https://repo.mysql.com/apt/ubuntu/dists/${CODENAME}/Release" >/dev/null; then
  echo "Oracle's MySQL APT repository has no packages for Ubuntu ${CODENAME}. Stop and choose another release." >&2
  exit 1
fi

if ! command -v mysqld >/dev/null || ! mysqld --version | grep -q ' 26\.7\.'; then
  install -d /usr/share/keyrings
  curl -fsSL https://repo.mysql.com/RPM-GPG-KEY-mysql-2025 | gpg --dearmor -o /usr/share/keyrings/mysql.gpg
  echo "deb [signed-by=/usr/share/keyrings/mysql.gpg] https://repo.mysql.com/apt/ubuntu ${CODENAME} mysql-innovation" \
    > /etc/apt/sources.list.d/mysql.list
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server
fi

mysqld --version | grep -q ' 26\.7\.' || { echo "Installed MySQL is not 26.7" >&2; exit 1; }

mysql -u root <<SQL
SET PERSIST log_bin_trust_function_creators = 1;
SET PERSIST binlog_expire_logs_seconds = 604800;
CREATE DATABASE IF NOT EXISTS \`${RMS_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'rms_app'@'localhost' IDENTIFIED BY '${RMS_APP_PASSWORD}';
CREATE USER IF NOT EXISTS 'rms_migrate'@'localhost' IDENTIFIED BY '${RMS_MIGRATE_PASSWORD}';
-- rms_migrate is the DEFINER of every trigger: rotate its password with ALTER USER, never drop it.
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON \`${RMS_DB}\`.* TO 'rms_app'@'localhost';
GRANT ALL PRIVILEGES ON \`${RMS_DB}\`.* TO 'rms_migrate'@'localhost';
SQL

mysql -u root -N -e "SELECT @@version, @@log_bin, @@log_bin_trust_function_creators"
echo "MySQL ready: database ${RMS_DB}, users rms_app and rms_migrate."
```
Before first use on a real server, verify the repository key file name and the `mysql-innovation` component against https://dev.mysql.com/doc/mysql-apt-repo-quick-guide/en/ (Oracle renames the key yearly) and update the two lines if they changed.

- [ ] **Step 2: Forge deploy script**

Create `deploy/forge-deploy.sh` (paste its contents into Forge → Site → Deployments; it relies on Forge's zero-downtime macros):
```bash
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
```

- [ ] **Step 3: The runbook**

Create `deploy/README.md`:
````markdown
# Operations runbook

Everything here follows spec §13. One server per company; every company runs the same release.

## 1. Accounts the product owner provides (STOP AND ASK if missing)
- Laravel Forge on the **Business** plan (server monitoring and team roles; Hobby allows only one custom VPS).
- A VPS per company at a **Bahrain-based provider** (not AWS me-south-1): 2 vCPU, 4 GB RAM, fresh Ubuntu 26.04 x64, root SSH. Location confirmed under C3.
- S3-compatible object storage at a **different provider**, one bucket per company (C3 decides the location).
- A Sentry project (one DSN for all installs) and an uptime-monitoring service.
- DNS for each company's hostname.

## 2. Provision a server
1. In Forge: Servers → Create → **Custom VPS**, Ubuntu 26.04, **no database** (Forge has no MySQL 26.x), PHP 8.5. Add Forge's key to `/root/.ssh/authorized_keys`.
2. As root on the server: copy `deploy/provision-mysql.sh`, generate two passwords into the vault, then
   `RMS_APP_PASSWORD=... RMS_MIGRATE_PASSWORD=... bash provision-mysql.sh`.
3. In Forge: create the site with **zero-downtime deployments** (only selectable at creation), repository = this repo, branch = **`release`**.
4. Site → Settings → Deployments → **Shared paths**: add `storage` (uploads must survive release pruning; `.env` is shared automatically).
5. Paste `deploy/forge-deploy.sh` as the deploy script. Enable SSL (Let's Encrypt).
6. Environment (`.env`), no secrets in git:
   ```
   APP_NAME="Rental Management"   APP_ENV=production   APP_DEBUG=false   APP_URL=https://<host>
   DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=rms
   DB_USERNAME=rms_app DB_PASSWORD=<vault>   DB_MIGRATOR_USERNAME=rms_migrate DB_MIGRATOR_PASSWORD=<vault>
   SESSION_DRIVER=database SESSION_LIFETIME=30 SESSION_SECURE_COOKIE=true CACHE_STORE=database QUEUE_CONNECTION=database
   MAIL_* (company mail provider)
   SENTRY_LARAVEL_DSN=<dsn> SENTRY_TRACES_SAMPLE_RATE=0 RMS_COMPANY_CODE=<short code>
   BACKUP_DISKS=backups-s3 BACKUP_ARCHIVE_PASSWORD=<vault> BACKUP_NOTIFY_EMAIL=<ops list> DB_DUMP_BINARY_PATH=/usr/bin
   BACKUP_S3_KEY/SECRET/REGION/BUCKET/ENDPOINT=<bucket>
   HEARTBEAT_BACKUP_CLEAN/RUN/MONITOR, HEARTBEAT_NUMBER_SEQUENCES=<Forge heartbeat URLs>
   ```
7. Forge → Site → **Queue**: `database` connection, 1 worker. Server → **Scheduler**: `php artisan schedule:run` every minute. Enable "Monitor with heartbeats" for each scheduled job and paste each ping URL into the matching `HEARTBEAT_*` variable.
8. Server → Observe → **Monitors**: CPU, disk (alert at 80 %), memory; notify the ops distribution list.
9. Point the uptime service at `https://<host>/health` (Forge's deployment health checks run only after deploys).

## 3. Releases
```bash
git tag v1.2.0 && git push origin v1.2.0
git push origin v1.2.0^{commit}:refs/heads/release --force   # Forge deploys the release branch head
```
Deploy to **staging first**, then to each company. Before any release with migrations, run them against a copy of the largest company's database on the restore-check server. The version each company runs is shown in its footer and on `/health`.

## 4. Staging
Staging is a demo install with **fake data only** — never real company data. After provisioning (section 2):
```bash
php artisan migrate --database=migrator --force
php artisan rms:install --company="Demo Properties W.L.L." --admin-name="Demo Admin" --admin-email=<demo admin email> --vendor-email=<vendor support email>
```
Store the printed Vendor Support password and TOTP URL in the vault immediately.

## 5. A company's go-live (spec §13.2)
1. Provision its server (section 2) and deploy the current release.
2. `rms:install` with the company's details; run the dry-run import and UAT on this server.
3. After UAT sign-off: drop the database and `storage/app/private`, recreate the database with `provision-mysql.sh`, deploy, `rms:install` again, run the final import, then `php artisan rms:setting go_live_at <YYYY-MM-DD>`.

## 6. Backups and the restore check
- Nightly `backup:run` (03:30) sends an AES-256 archive to `backups-s3`; `backup:clean` keeps 30 dailies + 12 monthlies; `backup:monitor` mails if the newest backup is older than a day.
- **Weekly restore check** on a dedicated restore-check server (same location, no live credentials, wiped after each run): check out the release, set `BACKUP_DISKS=backups-s3`, that company's bucket and `BACKUP_ARCHIVE_PASSWORD`, `RESTORE_DB_*` pointing at a scratch database, then
  `php artisan backup:restore --disk=backups-s3 --connection=restore --reset --keep --no-interaction` → expect "All health checks passed."
- Quarterly: a manual full restore drill onto a fresh server.

## 7. Support access
- Vendor Support logs in with the vault password and TOTP. Admin can deactivate it; only the vendor re-enables it: `php artisan rms:vendor-support --enable`.
- Install-level switches: `php artisan rms:setting require_different_approver false|true`, `php artisan rms:setting go_live_at YYYY-MM-DD`.
````

- [ ] **Step 4: Check the scripts**

```bash
bash -n deploy/provision-mysql.sh && echo "provision ok"
grep -c 'CREATE_RELEASE\|ACTIVATE_RELEASE' deploy/forge-deploy.sh
```
Expected: `provision ok` and `2`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -q -F - <<'EOF'
Add deploy scripts and the operations runbook

MySQL 26.7 provisioning from Oracle's APT repository with the two
database users, the Forge zero-downtime deploy script, and the runbook
for servers, releases, staging, go-live, backups and support access.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
EOF
```

- [ ] **Step 6: STOP AND ASK — staging server**

The M0 exit criterion "`rms:install` produces a working install on a fresh server" needs the accounts in runbook section 1. Ask the product owner for them, then follow runbook sections 2 and 4 for staging and confirm: login works, forced 2FA works, `/health` shows the release, a backup lands in the bucket, and the restore check passes.

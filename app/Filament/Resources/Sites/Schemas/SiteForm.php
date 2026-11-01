<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sites\Schemas;

use App\Enums\ImageSource;
use App\Enums\PhpVersion;
use App\Enums\WebRuntime;
use App\Rules\Hostname;
use App\Rules\Matches;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SiteForm
{
    /** git@host:owner/repo(.git) or https://host/path(.git); no local or file URLs. */
    public const string REPOSITORY_PATTERN = '~^(git@[a-z0-9-]+(\.[a-z0-9-]+)+:[\w.-]+(/[\w.-]+)+|https://[a-z0-9-]+(\.[a-z0-9-]+)+(/[\w.-]+)+)$~i';

    /** A git branch name that cannot be read as a command-line option. */
    public const string BRANCH_PATTERN = '~^(?!-)(?!.*\.\.)(?!.*//)(?!.*@\{)(?!.*\.lock$)[A-Za-z0-9._/-]{1,100}(?<![/.])$~';

    /** registry/namespace/name without a tag, e.g. ghcr.io/acme/shop. */
    public const string IMAGE_PATTERN = '~^[a-z0-9]+([._-][a-z0-9]+)*(:[0-9]+)?(/[a-z0-9]+([._-][a-z0-9]+)*)+$~';

    /** A file inside the repository, which cannot be read as an option or leave it. */
    public const string DOCKERFILE_PATTERN = '~^(?![-/.])(?!.*\.\.)[A-Za-z0-9._/-]{1,200}$~';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Site')
                    ->columns(2)
                    ->components([
                        Select::make('server_id')
                            ->relationship('server', 'name')
                            ->required()
                            ->disabledOn('edit')
                            ->helperText('Cannot be changed later.'),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(64),
                        TextInput::make('domain')
                            ->required()
                            ->maxLength(253)
                            ->rule(new Hostname)
                            ->unique(ignoreRecord: true)
                            ->helperText('Point its DNS A record at the server before deploying, so HTTPS can be issued.'),
                        Select::make('php_version')
                            ->label('PHP')
                            ->options(PhpVersion::class)
                            ->default(PhpVersion::Php84)
                            ->required(),
                    ]),

                Section::make('Source')
                    ->columns(2)
                    ->components([
                        TextInput::make('repository_url')
                            ->label('Git repository')
                            ->required()
                            ->maxLength(255)
                            ->rule(new Matches(self::REPOSITORY_PATTERN, 'Use an SSH (git@host:owner/repo.git) or HTTPS repository URL.'))
                            ->placeholder('git@github.com:acme/shop.git'),
                        TextInput::make('branch')
                            ->required()
                            ->default('main')
                            ->maxLength(100)
                            ->rule(new Matches(self::BRANCH_PATTERN, 'Not a valid branch name.')),
                        Select::make('image_source')
                            ->label('Image')
                            ->options(ImageSource::class)
                            ->default(ImageSource::Build)
                            ->required(),
                        TextInput::make('image_repository')
                            ->label('Image repository')
                            ->required()
                            ->maxLength(255)
                            ->rule(new Matches(self::IMAGE_PATTERN, 'Use a lowercase repository without a tag, like ghcr.io/acme/shop.'))
                            ->placeholder('ghcr.io/acme/shop')
                            ->helperText('Without a tag; each release is tagged with its commit.'),
                        Select::make('registry_id')
                            ->label('Registry credentials')
                            ->relationship('registry', 'name')
                            ->placeholder('None: push with my Docker login, pull anonymously')
                            ->helperText('Used to push the image from this machine and to pull it on the server.'),
                        TextInput::make('dockerfile_path')
                            ->label('Dockerfile')
                            ->maxLength(200)
                            ->rule(new Matches(self::DOCKERFILE_PATTERN, 'Use a path inside the repository, like Dockerfile.'))
                            ->placeholder('Default Laravel image')
                            ->helperText('Leave empty to use .mini-forge/Dockerfile or the default image.'),
                        Select::make('web_runtime')
                            ->options(WebRuntime::class)
                            ->default(WebRuntime::FrankenPhp)
                            ->required(),
                    ]),

                Section::make('Processes')
                    ->columns(2)
                    ->components([
                        Toggle::make('queue_enabled')
                            ->label('Queue worker')
                            ->default(true),
                        Toggle::make('scheduler_enabled')
                            ->label('Scheduler')
                            ->default(true),
                        Toggle::make('run_migrations')
                            ->label('Run migrations on deploy')
                            ->default(true),
                        TextInput::make('health_check_path')
                            ->label('Health check path')
                            ->required()
                            ->default('/up')
                            ->maxLength(100)
                            ->rule(new Matches('~^/[A-Za-z0-9._\~/-]*$~', 'Use a path like /up.')),
                    ]),
            ]);
    }
}

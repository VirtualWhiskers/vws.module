<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Dto;

final class ModuleConfig
{
	public function __construct(
		public readonly string $moduleId,
		public readonly string $vendor,
		public readonly string $name,
		public readonly string $moduleName,
		public readonly string $description,
		public readonly string $version,
		public readonly bool $tables,
		public readonly bool $events,
		public readonly bool $agents,
		public readonly array $installDirs,
		public readonly bool $public,
		public readonly bool $cli,
		public readonly bool $options,
	) {}
}

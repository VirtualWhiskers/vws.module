<?php

declare(strict_types=1);

namespace Vws\Module\Cli\Dto;

final class ConfigFileReader
{
	private const ALLOWED_KEYS = [
		'name' => 'string',
		'description' => 'string',
		'version' => 'string',
		'tables' => 'bool',
		'events' => 'bool',
		'agents' => 'bool',
		'installDirs' => 'string[]',
		'public' => 'bool',
		'cli' => 'bool',
		'options' => 'bool',
	];

	public static function read(string $path): array
	{
		if (!is_file($path))
		{
			throw new \InvalidArgumentException("Config file not found: {$path}");
		}

		$raw = file_get_contents($path);
		if ($raw === false)
		{
			throw new \InvalidArgumentException("Config file is not readable: {$path}");
		}

		try
		{
			$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException $exception)
		{
			throw new \InvalidArgumentException("Config file is not valid JSON: {$exception->getMessage()}");
		}

		if (!is_array($data))
		{
			throw new \InvalidArgumentException('Config file must contain a JSON object');
		}

		$unknown = array_diff(array_keys($data), array_keys(self::ALLOWED_KEYS));
		if ($unknown !== [])
		{
			throw new \InvalidArgumentException('Unknown config keys: ' . implode(', ', $unknown));
		}

		foreach ($data as $key => $value)
		{
			self::validateType($key, $value);
		}

		return $data;
	}

	private static function validateType(string $key, mixed $value): void
	{
		$expected = self::ALLOWED_KEYS[$key];

		$checker = match ($expected) {
			'string' => static fn (mixed $v): bool => is_string($v),
			'bool' => static fn (mixed $v): bool => is_bool($v),
			'string[]' => static fn (mixed $v): bool => is_array($v) && array_is_list($v) && count(array_filter($v, static fn (mixed $item): bool => is_string($item))) === count($v),
		};

		if (!$checker($value))
		{
			throw new \InvalidArgumentException("Config key \"{$key}\" must be of type {$expected}");
		}
	}
}

<?php

declare(strict_types=1);

namespace Thunder\Bolt\Tests\Functions;

use PHPUnit\Framework\TestCase;
use Thunder\Bolt\Attributes\FromInputs;
use Thunder\Bolt\Attributes\InputName;
use Thunder\Bolt\Functions\Inputs;
use Thunder\Bolt\Functions\InvalidInputs;

final class InputsTest extends TestCase
{
    public function test_reads_strict_scalar_and_array_inputs_without_coercion(): void
    {
        $inputs = new Inputs([
            'title' => 'Release notes',
            'retries' => 2,
            'ratio' => 0.5,
            'enabled' => true,
            'tags' => ['release', 'api'],
            'nullable' => null,
        ]);

        self::assertSame('Release notes', $inputs->string('title'));
        self::assertSame(2, $inputs->integer('retries'));
        self::assertSame(2, $inputs->number('retries'));
        self::assertSame(0.5, $inputs->number('ratio'));
        self::assertTrue($inputs->boolean('enabled'));
        self::assertSame(['release', 'api'], $inputs->array('tags'));
        self::assertTrue($inputs->has('nullable'));
        self::assertNull($inputs->get('nullable', 'fallback'));
        self::assertSame('fallback', $inputs->get('missing', 'fallback'));
        self::assertSame($inputs->toArray(), $inputs->jsonSerialize());

        try {
            $inputs->integer('title');
            self::fail('Expected strict integer read to fail.');
        } catch (InvalidInputs $exception) {
            self::assertSame('Input "title" must be an integer.', $exception->getMessage());
            self::assertStringNotContainsString('Release notes', $exception->getMessage());
        }
    }

    public function test_rejects_non_object_input_arrays_but_accepts_an_empty_object(): void
    {
        self::assertSame([], new Inputs([])->toArray());

        $this->expectException(InvalidInputs::class);
        new Inputs([0 => 'not an object']);
    }

    public function test_maps_public_promoted_dtos_with_aliases_defaults_nullable_nested_dtos_and_enums(): void
    {
        $inputs = new Inputs([
            'summary_text' => 'Ship the input mapper',
            'settings' => [
                'timeout_seconds' => 5,
                'verbose' => true,
                'unknown_setting' => 'retained only in raw input',
            ],
            'priority' => 'high',
            'future_field' => ['safe' => true],
        ]);

        $dto = $inputs->as(FunctionInputDto::class);

        self::assertSame('Ship the input mapper', $dto->summary);
        self::assertSame(3, $dto->retries);
        self::assertNull($dto->note);
        self::assertSame(5.0, $dto->settings->timeout);
        self::assertTrue($dto->settings->verbose);
        self::assertSame(InputPriority::High, $dto->priority);
    }

    public function test_reports_nested_field_paths_without_echoing_input_values(): void
    {
        $inputs = new Inputs([
            'summary_text' => 'Valid summary',
            'settings' => ['timeout_seconds' => 'secret-invalid-value', 'verbose' => true],
            'priority' => 'high',
        ]);

        try {
            $inputs->as(FunctionInputDto::class);
            self::fail('Expected nested strict type validation to fail.');
        } catch (InvalidInputs $exception) {
            self::assertSame('Input "settings.timeout_seconds" must be a number.', $exception->getMessage());
            self::assertStringNotContainsString('secret-invalid-value', $exception->getMessage());
        }
    }

    public function test_rejects_invalid_enum_values_and_missing_required_fields(): void
    {
        try {
            new Inputs([
                'summary_text' => 'Valid summary',
                'settings' => ['timeout_seconds' => 2, 'verbose' => false],
                'priority' => 'unrecognized',
            ])->as(FunctionInputDto::class);
            self::fail('Expected invalid enum to fail.');
        } catch (InvalidInputs $exception) {
            self::assertSame(
                'Input "priority" cannot be mapped: the value is not a valid enum case.',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('unrecognized', $exception->getMessage());
        }

        $this->expectExceptionMessage('Input "summary_text" is required.');
        new Inputs([
            'settings' => ['timeout_seconds' => 2, 'verbose' => false],
            'priority' => 'low',
        ])->as(FunctionInputDto::class);
    }

    public function test_attributes_are_parameter_markers_and_validate_aliases(): void
    {
        $reflection = new \ReflectionMethod(InputAttributeTarget::class, '__invoke');
        self::assertNotEmpty($reflection->getParameters()[0]->getAttributes(FromInputs::class));

        $this->expectException(\InvalidArgumentException::class);
        new InputName('   ');
    }
}

enum InputPriority: string
{
    case Low = 'low';
    case High = 'high';
}

final readonly class FunctionSettingsDto
{
    public function __construct(
        #[InputName('timeout_seconds')]
        public float $timeout,
        public bool $verbose,
    ) {}
}

final readonly class FunctionInputDto
{
    public function __construct(
        #[InputName('summary_text')]
        public string $summary,
        public FunctionSettingsDto $settings,
        public InputPriority $priority,
        #[InputName('retry_count')]
        public int $retries = 3,
        public ?string $note = null,
    ) {}
}

final class InputAttributeTarget
{
    public function __invoke(#[FromInputs] FunctionInputDto $input): void {}
}

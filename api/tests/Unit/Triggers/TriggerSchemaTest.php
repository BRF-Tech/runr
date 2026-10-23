<?php

namespace Tests\Unit\Triggers;

use App\Models\ProjectTrigger;
use App\Rules\CronSchedule;
use App\Triggers\TriggerSchema;
use App\Triggers\TriggerTypes;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\Support\TimeZones;
use Tests\TestCase;

/**
 * The runr.yaml trigger schema (App\Triggers\TriggerSchema): derived from the trigger types'
 * rules, committed as resources/triggers/triggers.schema.json and embedded by the CLI
 * (cli/internal/projectfile). tests/Fixtures/triggers/runr-yaml-cases.json holds entries with
 * the server's verdict; this test checks the verdicts against the server's rules, the CLI's test
 * (cli/internal/projectfile/schema_test.go) checks its schema validation against the same file.
 */
class TriggerSchemaTest extends TestCase
{
    public function test_the_committed_schema_is_the_one_the_rules_give(): void
    {
        $this->assertFileExists(TriggerSchema::path());
        $this->assertSame(
            app(TriggerSchema::class)->render(),
            file_get_contents(TriggerSchema::path()),
            'resources/triggers/triggers.schema.json is out of date: run `php artisan triggers:schema` and copy it to cli/internal/projectfile/triggers.schema.json',
        );
    }

    public function test_the_check_option_compares_without_writing(): void
    {
        $this->artisan('triggers:schema --check')->assertSuccessful();
    }

    public function test_every_registered_type_has_a_schema(): void
    {
        $schema = app(TriggerSchema::class)->build();

        $this->assertSame(1, $schema['version']);
        $this->assertSame(['type'], $schema['entry']['required']);
        $keys = app(TriggerTypes::class)->keys();
        sort($keys);
        $this->assertSame($keys, array_keys($schema['types']));
    }

    public function test_the_rules_map_to_keywords(): void
    {
        $types = app(TriggerSchema::class)->build()['types'];

        // schedule: the cron rule object and Laravel's timezone rule
        $this->assertSame(['type' => 'string', 'format' => 'cron'], $types['schedule']['properties']['cron']);
        $this->assertSame(['type' => ['string', 'null'], 'format' => 'timezone', 'maxLength' => 64], $types['schedule']['properties']['timezone']);
        $this->assertSame(['cron'], $types['schedule']['required']);

        // delivery of a gateway type, none for http
        $delivery = $types['schedule']['properties']['delivery'];
        $this->assertSame(['array', 'null'], $delivery['type']);
        $this->assertSame(['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 32], $delivery['properties']['concurrency']);
        $this->assertEquals(new \stdClass, $types['http']['properties']);

        // regex + not_regex, in
        $stream = $types['queue']['properties']['stream'];
        $this->assertSame('string', $stream['type']);
        $this->assertSame('^[a-zA-Z0-9._-]+\n?$', $stream['pattern']);
        $this->assertSame(['pattern' => '^runr\.'], $stream['not']);
        $this->assertSame(['earliest', 'latest'], $types['queue']['properties']['start_from']['enum']);

        // Rule::in of a list's elements, a required list has an element
        $events = $types['platform_event']['properties']['events'];
        $this->assertSame('array', $events['type']);
        $this->assertSame(1, $events['minItems']);
        $this->assertContains('*', $events['items']['enum']);
        $this->assertSame(['type' => 'string', 'minLength' => 26, 'maxLength' => 26], $types['platform_event']['properties']['projects']['items']);

        // elements that are mappings
        $bindings = $types['rabbitmq']['properties']['bindings'];
        $this->assertSame(['exchange'], $bindings['items']['required']);
        $this->assertSame(20, $bindings['maxItems']);

        // integer in:… compares as strings
        $this->assertSame(['0', '1', '2'], $types['mqtt']['properties']['qos']['enum']);
    }

    public function test_bounds_that_follow_the_configuration_are_left_out(): void
    {
        $webhook = app(TriggerSchema::class)->build()['types']['webhook']['properties'];

        // max_body_kb up to TRIGGERS_WEBHOOK_MAX_BODY_KB, retention_days up to TRIGGERS_EVENT_RETENTION_MAX_DAYS
        $this->assertSame(['type' => ['integer', 'null'], 'minimum' => 1], $webhook['max_body_kb']);
        $this->assertSame(['type' => ['integer', 'null'], 'minimum' => 1], $webhook['retention_days']);
        // a fixed bound stays
        $this->assertSame(86400, $webhook['tolerance_seconds']['maximum']);
    }

    public function test_patterns_are_converted_or_left_out(): void
    {
        $this->assertSame('^[a-z0-9]([-a-z0-9]{0,61}[a-z0-9])?\n?$', TriggerSchema::pattern(ProjectTrigger::NAME_PATTERN));
        $this->assertSame('^(nats|tls)://', TriggerSchema::pattern('#^(nats|tls)://#'));
        $this->assertSame('(?i)^abc\n?$', TriggerSchema::pattern('/^abc$/i'));
        $this->assertSame('^abc$', TriggerSchema::pattern('/^abc$/D'));
        $this->assertSame('a#b', TriggerSchema::pattern('#a\#b#'));
        // what RE2 cannot run
        $this->assertNull(TriggerSchema::pattern('/^(?!runr\.).+$/'));
        $this->assertNull(TriggerSchema::pattern('/(a)\1/'));
        $this->assertNull(TriggerSchema::pattern('/a++/'));
        $this->assertNull(TriggerSchema::pattern('/abc/x'));
        $this->assertNull(TriggerSchema::pattern(''));
    }

    public function test_rules_the_schema_cannot_say_are_left_out(): void
    {
        $schema = TriggerSchema::objectSchema([
            'a' => ['nullable', 'required_if:config.b,x', 'string', fn ($attribute, $value, $fail) => $fail('no')],
            'b' => ['required', Rule::in(['x', 'y "z"'])],
            'c' => ['string', new CronSchedule],
        ]);

        $this->assertSame(['type' => ['string', 'null']], $schema['properties']['a']);
        $this->assertSame(['enum' => ['x', 'y "z"']], $schema['properties']['b']);
        $this->assertSame(['type' => 'string', 'format' => 'cron'], $schema['properties']['c']);
        $this->assertSame(['b'], $schema['required']);
    }

    /**
     * The server's verdict on every case of the file the CLI's test reads too. A case marked
     * backward_zone names a backward-compatible zone (Europe/Kiev): the CLI knows it from Go's
     * embedded tzdata, the server from its PHP — this test takes a backward-compatible name the
     * PHP running it knows (tests/Support/TimeZones), so it does not depend on the host's tzdata.
     */
    public function test_the_shared_cases_hold_the_servers_verdict(): void
    {
        $cases = json_decode((string) file_get_contents(base_path('tests/Fixtures/triggers/runr-yaml-cases.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $entry = $case['entry'];
            if (! empty($case['backward_zone'])) {
                $backward = TimeZones::backward();
                if ($backward === null) {
                    continue; // this PHP knows no backward-compatible name: nothing to check
                }
                $entry['timezone'] = $backward;
            }
            $type = app(TriggerTypes::class)->get($entry['type']);
            $rules = ['name' => ['nullable', 'string', 'max:63', 'regex:'.ProjectTrigger::NAME_PATTERN], 'enabled' => 'nullable|boolean'];
            foreach ($type->rules() as $key => $rule) {
                $rules['config.'.$key] = $rule;
            }
            $config = array_diff_key($entry, array_flip(['type', 'name', 'enabled', 'secrets']));
            $data = ['config' => $config] + array_intersect_key($entry, array_flip(['name', 'enabled']));

            $validator = Validator::make($data, $rules);
            $this->assertSame($case['server'], $validator->passes(), $case['case'].': '.json_encode($validator->errors()->all()));
        }
    }
}

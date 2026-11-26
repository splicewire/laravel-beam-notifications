<?php

use Schemastud\DataSchemas\Migration\AcceptanceGate;
use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionDeclarations;
use Splicewire\Beam\Config\ConfigSectionSchema;
use Splicewire\Beam\Notifications\Config\BeamNotificationsSection;

/**
 * ADR-0245 Config Section for `beam.notifications`. The section is the declared shape of
 * config/beam/notifications.php: every key is host-ceilinged and deploy-only, and `recipient-kinds`
 * is a host-extensible map (the registry splicewire/laravel-beam-accounts appends `to_roles` /
 * `to_teams` into), so its coverage must hold for a POPULATED map, not only the single shipped `to`.
 */
it('validates the package default config through the acceptance gate', function () {
    $config = config('beam.notifications');

    $schema = app(ConfigSectionSchema::class)->for(BeamNotificationsSection::class);

    expect(app(AcceptanceGate::class)->accepts($config, $schema))->toBeTrue();
});

it('covers every shipped key with a clean Host-tier / Deploy-effect declarations walk', function () {
    $config = config('beam.notifications');

    // Shipped recipient-kinds is already a non-empty map (`to` => AddressRecipientKind), so this is
    // not vacuous for that key — an unshaped array would report /recipient-kinds here.
    expect(ConfigSectionCoverage::uncovered(BeamNotificationsSection::class, $config))->toBe([]);

    // Every key is a bare #[ConfigKey] = ceiling: Host, effect: Deploy, carries no secret → no finding.
    expect(ConfigSectionDeclarations::findings(BeamNotificationsSection::class))->toBe([]);
});

it('covers a host-populated recipient-kinds map by its wire name and still passes the acceptance gate', function () {
    // `recipient-kinds` is a wire (kebab) key reached via #[MapName('recipient-kinds')], and a map of
    // keyword key => class-string reached via #[MapValues('string')]. A real host (or beam-accounts)
    // adds kinds beyond the shipped `to` — `to_roles`, `to_teams`, or its own. Populate the key under
    // its WIRE name with a 2-entry class-string map and assert BOTH readers see it: coverage keys by
    // the wire name (uncovered must stay empty, descending into the populated map), and the acceptance
    // gate must still validate the populated candidate. This is the non-vacuous lock on the map shape.
    $config = config('beam.notifications');
    $config['recipient-kinds'] = [
        'to' => Splicewire\Beam\Notifications\Recipients\Kinds\AddressRecipientKind::class,
        'to_roles' => 'App\\Notifications\\FakeRolesRecipientKind',
    ];

    expect(ConfigSectionCoverage::uncovered(BeamNotificationsSection::class, $config))->toBe([]);

    $schema = app(ConfigSectionSchema::class)->for(BeamNotificationsSection::class);
    expect(app(AcceptanceGate::class)->accepts($config, $schema))->toBeTrue();
});

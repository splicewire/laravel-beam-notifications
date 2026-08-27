<?php

namespace Splicewire\Beam\Notifications\Contracts;

/**
 * The port through which a recipient kind asks "who holds this role / who is in this team", without
 * this package ever learning what an account is.
 *
 * ## Why a port rather than `Membership::query()` — this one is evidence, not layering taste
 *
 * beam-facade 100 measured the flagship: `~/Herd/splicewire-app` has **no `teams` and no `memberships`
 * migrations**, does not bind `beam.accounts.teams.resolver`, and carries its own `tenant_users` pivot
 * (`tenant_id, user_id, role`) left over from before beam-accounts was extracted. A `to_roles:` kind
 * that queried `Membership` directly would resolve **nobody at the flagship, silently** — which is the
 * exact defect class this map keeps finding, an instrument that reports success by not running.
 *
 * The owner's ruling is that the flagship refactors ONTO beam-accounts rather than being accommodated
 * by a bespoke binding (beam-facade 155), so the port is not there to host a permanent second
 * implementation. It is there because the seam has to exist for that refactor to be a swap rather than
 * a rewrite, and because a package that names a `User`, a `Role` or a `Team` has taken the dependency
 * this one exists not to take.
 *
 * The vocabulary is deliberately thin: a directory answers WHO, in notifiable terms, and nothing else.
 * No team model, no role enum, no membership lifecycle crosses this boundary.
 *
 * ## Scope is the connection
 *
 * Both methods answer within the current database connection and nothing narrower (100 D4). A
 * multi-tenant host is isolated by construction — stancl swaps the default connection — and a
 * single-DB host resolves within its one database. An `in_team:` scope MODIFIER is the declared growth
 * path; it is NOT built here, and an implementation must not invent ambient team state to simulate one.
 *
 * Registered by `splicewire/laravel-beam-accounts`. A host with no accounts package binds nothing, and
 * nothing asks — the `to_roles` / `to_teams` kinds that consume this port are registered by the same
 * package that binds it, so the port and its only caller arrive together.
 */
interface AccountsDirectory
{
    /**
     * Every notifiable member holding `$role`, anywhere in the current connection.
     *
     * `$role` names the MEMBERSHIP vocabulary (`owner`, `admin`, `member` on day one), not an arbitrary
     * permission role — see beam-facade 156 for why: `syncRoles()` is replace-all, so a hand-assigned
     * extra permission role is silently wiped by the next membership write, and a `to_roles:` reading
     * those would work until someone edited a membership and then quietly stop.
     *
     * @return list<object> Notifiable models. Empty is a legitimate answer; the CALLER decides it is a
     *                      fault (100 D3), because only the caller knows a notification was pending.
     */
    public function membersOfRole(string $role): array;

    /**
     * Every notifiable member of the team named by `$team` — its slug, as authored in a schema.
     *
     * A slug rather than a key because the selector is written by hand into a JSON Schema that travels
     * between hosts; an auto-increment id in a schema document is unportable by construction. The slug
     * is globally unique (100 D5).
     *
     * @return list<object> Notifiable models. Empty is a legitimate answer, including for a team that
     *                      does not exist — an implementation is not obliged to tell the two apart.
     */
    public function membersOfTeam(string $team): array;
}

<?php

declare(strict_types=1);

/**
 * Not a test PHPUnit runs: PHPStan reads it. Each assertType() fails the
 * analysis if the toolbox's generics stop carrying a request's result type
 * through send().
 */

use JustSteveKing\SdkToolbox\Http\Response;
use JustSteveKing\SdkToolbox\Testing\FakeClient;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Acme;
use JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\GetProject;

use function PHPStan\Testing\assertType;

$acme = new Acme('token', new FakeClient);

assertType(JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Project::class, $acme->send(new GetProject('prj_1')));
assertType(Response::class, $acme->response(new GetProject('prj_1')));
assertType(JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Project::class, $acme->poll(new GetProject('prj_1'), fn (Response $response): bool => true));
assertType(Acme::class, $acme->withHeaders(['X-Trace' => '1']));

assertType(JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Member::class, $acme->send(new JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\GetMember('m_1')));
assertType('list<'.JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Member::class.'>', $acme->send(new JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\ListMembers));
assertType('JustSteveKing\SdkToolbox\Pagination\Paginator<JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Member>', $acme->paginate(new JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\ListMembers, new JustSteveKing\SdkToolbox\Pagination\LinkPagination));

foreach ($acme->paginate(new JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\ListMembers, new JustSteveKing\SdkToolbox\Pagination\LinkPagination) as $member) {
    assertType(JustSteveKing\SdkToolbox\Tests\Fixtures\Acme\Member::class, $member);
}

<?php

namespace LdapRecord\Tests\Auth;

use LdapRecord\Auth\Guard;
use LdapRecord\Auth\BindException;
use LdapRecord\Tests\TestCase;
use LdapRecord\Connections\Ldap;
use LdapRecord\Connections\DetailedError;
use LdapRecord\Events\Dispatcher;
use LdapRecord\Auth\Events\Bound;
use LdapRecord\Auth\Events\Binding;
use LdapRecord\Auth\Events\Passed;
use LdapRecord\Auth\Events\Attempting;
use LdapRecord\Configuration\DomainConfiguration;

class GuardTest extends TestCase
{
    public function test_validate_username()
    {
        $this->expectException(\LdapRecord\Auth\UsernameRequiredException::class);

        $guard = new Guard(new Ldap(), new DomainConfiguration());

        $guard->attempt('', 'password');
    }

    public function test_validate_password()
    {
        $this->expectException(\LdapRecord\Auth\PasswordRequiredException::class);

        $guard = new Guard(new Ldap(), new DomainConfiguration());

        $guard->attempt('username', '');
    }

    public function test_attempt()
    {
        $config = $this->mock(DomainConfiguration::class);

        $config->shouldReceive('get')->withArgs(['username'])->once();
        $config->shouldReceive('get')->withArgs(['password'])->once();

        $ldap = $this->mock(Ldap::class);
        $ldap->shouldReceive('bind')->twice()->andReturn(true);

        $guard = new Guard($ldap, $config);

        $this->assertTrue($guard->attempt('username', 'password'));
    }

    public function test_bind_using_credentials()
    {
        $config = $this->mock(DomainConfiguration::class);

        $ldap = $this->mock(Ldap::class);

        $ldap->shouldReceive('bind')->once()->withArgs(['username', 'password'])->andReturn(true);

        $guard = new Guard($ldap, $config);

        $this->assertNull($guard->bind('username', 'password'));
    }
    
    public function test_bind_always_throws_exception_on_invalid_credentials()
    {
        $config = $this->mock(DomainConfiguration::class);

        $ldap = $this->mock(Ldap::class);
        $ldap->shouldReceive('bind')->once()->withArgs(['username', 'password'])->andReturn(false);
        $ldap->shouldReceive('getLastError')->once()->andReturn('error');
        $ldap->shouldReceive('getDetailedError')->once()->andReturn(new DetailedError(42, 'Invalid credentials', '80090308: LdapErr: DSID-0C09042A'));
        $ldap->shouldReceive('errNo')->once()->andReturn(1);

        $guard = new Guard($ldap, $config);

        $this->expectException(BindException::class);
        $guard->bind('username', 'password');
    }

    public function test_bind_as_administrator()
    {
        $config = $this->mock(DomainConfiguration::class);
        $config->shouldReceive('get')->withArgs(['username'])->once()->andReturn('admin');
        $config->shouldReceive('get')->withArgs(['password'])->once()->andReturn('password');

        $ldap = $this->mock(Ldap::class);
        $ldap->shouldReceive('bind')->once()->withArgs(['admin', 'password'])->andReturn(true);

        $guard = new Guard($ldap, $config);

        $this->assertNull($guard->bindAsAdministrator());
    }

    public function test_binding_events_are_fired_during_bind()
    {
        $ldap = $this->mock(Ldap::class);

        $ldap->shouldReceive('bind')->once()->withArgs(['johndoe', 'secret'])->andReturn(true);

        $events = new Dispatcher();

        $firedBinding = false;
        $firedBound = false;

        $events->listen(Binding::class, function (Binding $event) use (&$firedBinding) {
            $this->assertEquals($event->getUsername(), 'johndoe');
            $this->assertEquals($event->getPassword(), 'secret');

            $firedBinding = true;
        });

        $events->listen(Bound::class, function (Bound $event) use (&$firedBound) {
            $this->assertEquals($event->getUsername(), 'johndoe');
            $this->assertEquals($event->getPassword(), 'secret');

            $firedBound = true;
        });

        $guard = new Guard($ldap, new DomainConfiguration([]));

        $guard->setDispatcher($events);

        $guard->bind('johndoe', 'secret');

        $this->assertTrue($firedBinding);
        $this->assertTrue($firedBound);
    }

    public function test_auth_events_are_fired_during_attempt()
    {
        $ldap = $this->mock(Ldap::class);
        $ldap->shouldReceive('bind')->once()->withArgs(['johndoe', 'secret'])->andReturn(true);

        $events = new Dispatcher();

        $firedBinding = false;
        $firedBound = false;
        $firedAttempting = false;
        $firedPassed = false;

        $events->listen(Binding::class, function (Binding $event) use (&$firedBinding) {
            $this->assertEquals($event->getUsername(), 'johndoe');
            $this->assertEquals($event->getPassword(), 'secret');

            $firedBinding = true;
        });

        $events->listen(Bound::class, function (Bound $event) use (&$firedBound) {
            $this->assertEquals($event->getUsername(), 'johndoe');
            $this->assertEquals($event->getPassword(), 'secret');

            $firedBound = true;
        });

        $events->listen(Attempting::class, function (Attempting $event) use (&$firedAttempting) {
            $this->assertEquals($event->getUsername(), 'johndoe');
            $this->assertEquals($event->getPassword(), 'secret');

            $firedAttempting = true;
        });

        $events->listen(Passed::class, function (Passed $event) use (&$firedPassed) {
            $this->assertEquals($event->getUsername(), 'johndoe');
            $this->assertEquals($event->getPassword(), 'secret');

            $firedPassed = true;
        });

        $guard = new Guard($ldap, new DomainConfiguration());

        $guard->setDispatcher($events);

        $this->assertTrue($guard->attempt('johndoe', 'secret', $bindAsUser = true));

        $this->assertTrue($firedBinding);
        $this->assertTrue($firedBound);
        $this->assertTrue($firedAttempting);
        $this->assertTrue($firedPassed);
    }

    public function test_all_auth_events_can_be_listened_to_with_wildcard()
    {
        $ldap = $this->mock(Ldap::class);
        $ldap->shouldReceive('bind')->once()->withArgs(['johndoe', 'secret'])->andReturn(true);

        $events = new Dispatcher();

        $totalFired = 0;

        $events->listen('LdapRecord\Auth\Events\*', function ($eventName) use (&$totalFired) {
            $totalFired++;
        });

        $guard = new Guard($ldap, new DomainConfiguration());
        $guard->setDispatcher($events);

        $this->assertTrue($guard->attempt('johndoe', 'secret', $bindAsUser = true));
        $this->assertEquals($totalFired, 4);
    }
}

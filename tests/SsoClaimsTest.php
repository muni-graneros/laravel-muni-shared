<?php

use Muni\Shared\Sso\SsoClaims;

it('email_verified true (bool o string) es verificado; ausente o false no', function () {
    expect(SsoClaims::emailVerificado(['email_verified' => true]))->toBeTrue();
    expect(SsoClaims::emailVerificado(['email_verified' => 'true']))->toBeTrue();
    expect(SsoClaims::emailVerificado(['email_verified' => false]))->toBeFalse();
    expect(SsoClaims::emailVerificado([]))->toBeFalse(); // ausente = no verificado
});

it('toma el RUT del claim rut y lo normaliza', function () {
    // 6.111.222-7 es un RUT con DV válido.
    expect(SsoClaims::rutDeClaims(['rut' => '6.111.222-7']))->toBe('6111222-7');
});

it('cae a preferred_username solo si es un RUT válido', function () {
    expect(SsoClaims::rutDeClaims(['preferred_username' => '6.111.222-7']))->toBe('6111222-7');
    // username que no es RUT → no se toma
    expect(SsoClaims::rutDeClaims(['preferred_username' => 'jperez']))->toBeNull();
});

it('rechaza un RUT con dígito verificador inválido', function () {
    expect(SsoClaims::rutDeClaims(['rut' => '6.111.222-0']))->toBeNull();
});

it('sin claims de RUT devuelve null', function () {
    expect(SsoClaims::rutDeClaims(['email' => 'x@y.cl']))->toBeNull();
});

it('mapea resource_access.<client>.roles a roles locales', function () {
    $claims = ['resource_access' => ['licencias' => ['roles' => ['administrador', 'funcionario', 'desconocido']]]];
    $mapa = ['administrador' => 'super_admin', 'funcionario' => 'funcionario'];

    expect(SsoClaims::rolesLocalesDesdeKeycloak($claims, 'licencias', $mapa))
        ->toBe(['super_admin', 'funcionario']); // 'desconocido' no está en el mapa → fuera
});

it('sin roles para el client devuelve lista vacía (no autorizado)', function () {
    expect(SsoClaims::rolesLocalesDesdeKeycloak([], 'licencias', ['x' => 'y']))->toBe([]);
    expect(SsoClaims::rolesLocalesDesdeKeycloak(
        ['resource_access' => ['otro' => ['roles' => ['administrador']]]],
        'licencias',
        ['administrador' => 'super_admin'],
    ))->toBe([]);
});

import assert from 'node:assert/strict';
import test, { afterEach, beforeEach } from 'node:test';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { can, getCurrentUserPermissions, hasRole, usePermission } from '../../resources/js/Hooks/usePermission.ts';
import { NAVIGATION_CONFIGS } from '../../resources/js/Components/Layout/navigationConfig.ts';

function createLocalStorageMock() {
  const store = new Map();
  return {
    getItem(key) {
      return store.has(key) ? store.get(key) : null;
    },
    setItem(key, value) {
      store.set(key, String(value));
    },
    removeItem(key) {
      store.delete(key);
    },
    clear() {
      store.clear();
    },
  };
}

function setUserPermissions(permissions, extras = {}) {
  globalThis.localStorage.setItem(
    'smart_cassavas_user',
    JSON.stringify({
      id: 'user-test-1',
      username: 'test_user',
      role_codes: ['RESIDENT'],
      permissions,
      is_super_admin: false,
      ...extras,
    })
  );
  globalThis.localStorage.removeItem('smartcassavas_session');
}

function evaluateHook(checkFn) {
  let result;

  function Harness() {
    const permApi = usePermission();
    result = checkFn(permApi);
    return null;
  }

  renderToStaticMarkup(React.createElement(Harness));
  return result;
}

const originalLocalStorage = globalThis.localStorage;
const originalWindow = globalThis.window;

beforeEach(() => {
  globalThis.localStorage = createLocalStorageMock();
  globalThis.window = {
    addEventListener() {},
    removeEventListener() {},
  };
});

afterEach(() => {
  globalThis.localStorage = originalLocalStorage;
  globalThis.window = originalWindow;
});

const specialMatrix = [
  { name: "grants SPECIAL check when user has 'SPECIAL'", permissions: ['SPECIAL'], check: 'SPECIAL', expected: true },
  { name: "grants SPECIAL check when user has 'SYSTEM:SPECIAL'", permissions: ['SYSTEM:SPECIAL'], check: 'SPECIAL', expected: true },
  { name: "denies SPECIAL check when user only has 'USER:SPECIAL'", permissions: ['USER:SPECIAL'], check: 'SPECIAL', expected: false },
  { name: "denies SPECIAL check when user only has 'VEHICLE:SPECIAL'", permissions: ['VEHICLE:SPECIAL'], check: 'SPECIAL', expected: false },
  { name: "denies SPECIAL check when user only has 'VISITOR:SPECIAL'", permissions: ['VISITOR:SPECIAL'], check: 'SPECIAL', expected: false },
  { name: "denies SPECIAL check when user only has 'USER:VIEW'", permissions: ['USER:VIEW'], check: 'SPECIAL', expected: false },
  { name: "grants 'USER:VIEW' check when user has 'USER:VIEW'", permissions: ['USER:VIEW'], check: 'USER:VIEW', expected: true },
];

for (const scenario of specialMatrix) {
  test(`standalone can() & usePermission().can: ${scenario.name}`, () => {
    setUserPermissions(scenario.permissions);

    const standaloneResult = can(scenario.check);
    const hookResult = evaluateHook((api) => api.can(scenario.check));

    assert.equal(standaloneResult, scenario.expected);
    assert.equal(hookResult, scenario.expected);
  });
}

test('denies SPECIAL when user has multiple module :SPECIAL permissions without SPECIAL or SYSTEM:SPECIAL', () => {
  setUserPermissions(['USER:SPECIAL', 'VEHICLE:SPECIAL', 'VISITOR:SPECIAL', 'USER:VIEW']);

  assert.equal(can('SPECIAL'), false);
  assert.equal(can('special'), false);
  assert.equal(evaluateHook((api) => api.can('SPECIAL')), false);
  assert.equal(evaluateHook((api) => api.can(' special ')), false);

  // Explicit module permission checks still work normally
  assert.equal(can('USER:SPECIAL'), true);
  assert.equal(can('VEHICLE:SPECIAL'), true);
  assert.equal(can('VISITOR:SPECIAL'), true);
  assert.equal(evaluateHook((api) => api.can('USER:SPECIAL')), true);
  assert.equal(evaluateHook((api) => api.can('VEHICLE:SPECIAL')), true);
  assert.equal(evaluateHook((api) => api.can('VISITOR:SPECIAL')), true);
});

test('handles trimmed/lowercase special and OR-logic regular permissions consistently', () => {
  setUserPermissions(['SYSTEM:SPECIAL', 'USER:VIEW']);

  assert.equal(can(' special '), true);
  assert.equal(evaluateHook((api) => api.can(' special ')), true);
  assert.equal(can('VEHICLE:VIEW|USER:VIEW'), true);
  assert.equal(evaluateHook((api) => api.can('VEHICLE:VIEW|USER:VIEW')), true);
  assert.equal(can('VEHICLE:VIEW|VEHICLE:CREATE'), false);
  assert.equal(evaluateHook((api) => api.can('VEHICLE:VIEW|VEHICLE:CREATE')), false);
});

test('keeps SUPER_ADMIN bypass and role helpers intact', () => {
  setUserPermissions([], { role_codes: ['SUPER_ADMIN'], is_super_admin: true });

  const state = getCurrentUserPermissions();
  assert.equal(state.isSuperAdmin, true);
  assert.equal(can('SPECIAL'), true);
  assert.equal(evaluateHook((api) => api.can('SPECIAL')), true);
  assert.equal(hasRole('SUPER_ADMIN'), true);
  assert.equal(evaluateHook((api) => api.hasRole('SUPER_ADMIN')), true);
});

test('BUG-M1-08: Sidebar menu items require VIEW permissions and prevent unauthorized visibility', () => {
  // Helper implementing the exact AppSidebar filtering logic
  function filterSidebarItems(items, role, canFn) {
    return items.filter((item) => {
      if (item.id === 'roles') {
        return canFn('ROLE:VIEW|USER:VIEW');
      }
      if (item.id === 'amenities') {
        return role === 'resident' || canFn('AMENITY:VIEW');
      }
      if (item.id === 'billing') {
        return role === 'resident' || canFn('INVOICE:VIEW');
      }
      if (item.id === 'tickets') {
        return role === 'resident' || canFn('TICKET:VIEW');
      }
      if (item.id === 'reports') {
        return canFn('REPORT:VIEW');
      }
      if (item.id === 'residents') {
        return canFn('RESIDENT:VIEW|USER:VIEW');
      }
      if (item.id === 'account_provisioning') {
        return canFn('USER:CREATE|USER:VIEW');
      }
      if (item.id === 'temporary_registrations') {
        return role === 'resident' || canFn('TEMP_REG:VIEW|TEMPORARY_RESIDENCE:VIEW|RESIDENT:VIEW|USER:VIEW');
      }
      if (item.id === 'schedules') {
        return canFn('SCHEDULE:VIEW|STAFF_SCHEDULE:VIEW|USER:VIEW');
      }
      if (item.id === 'parking') {
        return canFn('VEHICLE:VIEW');
      }
      if (item.id === 'vehicle_reg') {
        return canFn('VEHICLE:VIEW|VEHICLE:CREATE');
      }
      if (item.id === 'parcels') {
        return canFn('PARCEL:VIEW|RECEPTION:OPERATE|USER:VIEW');
      }
      if (item.id === 'keys') {
        return canFn('ACCESS_CARD:VIEW|RFID:VIEW|KEY:VIEW|RECEPTION:OPERATE|USER:VIEW');
      }
      if (item.id === 'visitors') {
        return role === 'resident' || canFn('VISITOR:VIEW|VISITOR:CREATE');
      }
      if (item.id === 'pricing_configs') {
        return canFn('PRICING:VIEW|VEHICLE:VIEW|INVOICE:VIEW');
      }

      return true;
    });
  }

  // 1. User without VIEW permissions sees NO protected items
  setUserPermissions([]);
  const managerItems = NAVIGATION_CONFIGS.manager.items;
  const filteredManagerNoPerm = filterSidebarItems(managerItems, 'manager', can);
  const visibleIdsNoPerm = filteredManagerNoPerm.map((it) => it.id);

  for (const protectedId of [
    'roles', 'amenities', 'billing', 'tickets', 'reports', 'residents',
    'account_provisioning', 'temporary_registrations', 'schedules',
    'parking', 'pricing_configs'
  ]) {
    assert.equal(visibleIdsNoPerm.includes(protectedId), false, `Item ${protectedId} should be hidden without permissions`);
  }

  // 2. Unprotected items (overview, blocks, etc.) remain visible
  assert.equal(visibleIdsNoPerm.includes('overview'), true);
  assert.equal(visibleIdsNoPerm.includes('blocks'), true);

  // 3. User with specific VIEW permissions sees only those items
  setUserPermissions(['VEHICLE:VIEW']);
  const filteredVehicleOnly = filterSidebarItems(managerItems, 'manager', can).map((it) => it.id);
  assert.equal(filteredVehicleOnly.includes('parking'), true);
  assert.equal(filteredVehicleOnly.includes('pricing_configs'), true);
  assert.equal(filteredVehicleOnly.includes('residents'), false);
  assert.equal(filteredVehicleOnly.includes('account_provisioning'), false);
  assert.equal(filteredVehicleOnly.includes('schedules'), false);

  // 4. User with only WRITE/UPDATE/DELETE (e.g. VEHICLE:DELETE) without VIEW cannot see the menu
  setUserPermissions(['VEHICLE:DELETE', 'RESIDENT:DELETE', 'USER:DELETE']);
  const filteredDeleteOnly = filterSidebarItems(managerItems, 'manager', can).map((it) => it.id);
  assert.equal(filteredDeleteOnly.includes('parking'), false);
  assert.equal(filteredDeleteOnly.includes('residents'), false);

  // 5. Cư dân trong Cổng Cư Dân (role === 'resident') thấy các mục cư dân cơ bản nhưng không thấy menu quản trị
  setUserPermissions([], { role_codes: ['RESIDENT_OWNER'] });
  const residentItems = NAVIGATION_CONFIGS.resident.items;
  const filteredResident = filterSidebarItems(residentItems, 'resident', can).map((it) => it.id);
  assert.equal(filteredResident.includes('amenities'), true);
  assert.equal(filteredResident.includes('billing'), true);
  assert.equal(filteredResident.includes('tickets'), true);
  assert.equal(filteredResident.includes('visitors'), true);
  assert.equal(filteredResident.includes('temporary_registrations'), true);

  // Cư dân truy cập manager items bị ẩn toàn bộ
  const residentAccessManagerItems = filterSidebarItems(managerItems, 'resident', can).map((it) => it.id);
  assert.equal(residentAccessManagerItems.includes('residents'), false);
  assert.equal(residentAccessManagerItems.includes('account_provisioning'), false);
  assert.equal(residentAccessManagerItems.includes('schedules'), false);
  assert.equal(residentAccessManagerItems.includes('pricing_configs'), false);

  // 6. SUPER_ADMIN thấy toàn bộ menu
  setUserPermissions([], { role_codes: ['SUPER_ADMIN'], is_super_admin: true });
  const saManagerItems = filterSidebarItems(managerItems, 'manager', can).map((it) => it.id);
  assert.equal(saManagerItems.includes('residents'), true);
  assert.equal(saManagerItems.includes('account_provisioning'), true);
  assert.equal(saManagerItems.includes('temporary_registrations'), true);
  assert.equal(saManagerItems.includes('schedules'), true);
  assert.equal(saManagerItems.includes('parking'), true);
  assert.equal(saManagerItems.includes('pricing_configs'), true);
});


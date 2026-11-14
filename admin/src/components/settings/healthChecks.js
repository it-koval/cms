// The chip of one check of «Состояние сайта»: `off` (a module that is switched off or not connected) is yellow like a warning, but it is not one:
// the overall status of the report does not change because of it.
const CHIPS = {
  ok: ['pub', 'settings.health_check_ok'],
  off: ['warn', 'settings.health_check_off'],
  warn: ['warn', 'settings.health_check_warn'],
  fail: ['fail', 'settings.health_check_fail'],
}

/** [chip class, label] of a check status; a status the screen does not know has no class and shows itself. */
export function checkChip(status, t) {
  const chip = CHIPS[status]
  return chip ? [chip[0], t(chip[1])] : ['', String(status)]
}

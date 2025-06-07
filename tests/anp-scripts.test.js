const assert = require('node:assert');
const { describe, it } = require('node:test');
// Stub a minimal jQuery implementation so the plugin code can execute in Node
function stubElement() {
  return {
    on: () => stubElement(),
    trigger: () => stubElement(),
    append: () => stubElement(),
    hide: () => stubElement(),
    show: () => stubElement(),
    text: () => stubElement(),
    empty: () => stubElement(),
    addClass: () => stubElement()
  };
}

global.jQuery = (arg) => {
  if (typeof arg === 'function') {
    arg(global.jQuery);
    return stubElement();
  }
  return stubElement();
};

const { classifyNutrient } = require('../assets/js/anp-scripts');

describe('classifyNutrient', () => {
  it('returns null for invalid inputs', () => {
    assert.strictEqual(classifyNutrient('energy_kcal', null), null);
    assert.strictEqual(classifyNutrient('energy_kcal', 'abc'), null);
    assert.strictEqual(classifyNutrient('unknown', 10), null);
  });

  it('classifies energy correctly', () => {
    assert.strictEqual(classifyNutrient('energy_kcal', 100), 'low');
    assert.strictEqual(classifyNutrient('energy_kcal', 200), 'medium');
    assert.strictEqual(classifyNutrient('energy_kcal', 400), 'high');
  });

  it('inverts classification for fiber', () => {
    assert.strictEqual(classifyNutrient('fiber_g', 2), 'high');
    assert.strictEqual(classifyNutrient('fiber_g', 4), 'medium');
    assert.strictEqual(classifyNutrient('fiber_g', 7), 'low');
  });
});

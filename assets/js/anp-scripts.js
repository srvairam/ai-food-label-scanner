jQuery(function($){
  // Version 1.1.5 - annotated sections
  // ----- Image capture -----
  // When user clicks “📸 Scan Label”
  $('#anp-scan-btn').on('click', function() {
    const input = $('<input type="file" accept="image/*" capture="environment">');
    input.on('change', function(){
      const file = this.files[0];
      if (!file) return;
      resizeAndSend(file);
    });
    input.trigger('click');
  });

// ----- Resize and upload -----
function resizeAndSend(file) {
  const reader = new FileReader();

  reader.onload = function (e) {
    const img = new Image();
    img.onload = function () {
      // 1) Resize so max width = 800px
      const maxW = 800;
      let { width: w, height: h } = img;
      if (w > maxW) {
        h = (h * maxW) / w;
        w = maxW;
      }

      // 2) Draw original image onto offscreen canvas
      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, w, h);

      // Previously we converted to grayscale, boosted contrast,
      // applied blur and thresholding. These steps caused the
      // OCR preview to appear heavily distorted. We now skip
      // these transformations so the server receives a
      // minimally processed image.

      // Export as JPEG (80% quality) and send to server
      const resizedDataUrl = canvas.toDataURL('image/jpeg', 0.8);
      sendScan(resizedDataUrl);
    };

    img.src = e.target.result;
  };

  reader.readAsDataURL(file);
}

  // Determine traffic-light level for a nutrient value.
  function classifyNutrient(key, val) {
    if (val == null || isNaN(val)) return null;
    const v = parseFloat(val);
    const t = {
      energy_kcal: { low: 120, high: 360 },
      fat_g:       { low: 3,   high: 17.5 },
      saturates_g: { low: 1.5, high: 5 },
      carbohydrate_g: { low: 15, high: 30 },
      sugars_g:    { low: 5,   high: 22.5 },
      fiber_g:     { low: 3,   high: 6, invert: true },
      protein_g:   { low: 3,   high: 10, invert: true },
      salt_g:      { low: 0.3, high: 1.5 }
    }[key];
    if (!t) return null;
    const { low, high, invert } = t;
    if (v <= low) return invert ? 'high' : 'low';
    if (v >= high) return invert ? 'low' : 'high';
    return 'medium';
  }


  // ----- AJAX upload -----
  function sendScan(base64Image) {
    $('#anp-loading').show();
    $.post(anp_ajax.ajax_url, {
      action: 'anp_scan',
      nonce: anp_ajax.nonce,
      image: base64Image
    }).done(function(resp){
      $('#anp-loading').hide();
      if (resp.success) {
        renderTiles(resp.data);
      } else {
        alert('Error: ' + resp.data);
      }
    }).fail(function(err){
      $('#anp-loading').hide();
      alert('Request failed. Please check your console.');
      console.error(err);
    });
  }

  // Temporary alias for older code referencing sendToServer
  function sendToServer(base64Image) {
    sendScan(base64Image);
  }
  // ----- Render result tiles -----
  function renderTiles(analysis) {
    const container = $('#anp-tiles').empty();

    if (analysis.product_name) {
      container.append(
        $('<div>')
          .addClass('anp-tile anp-product-tile')
          .text('Product: ' + analysis.product_name)
      );
    } else {
      container.append(
        $('<div>')
          .addClass('anp-tile anp-product-tile')
          .text('No product name available')
      );
    }

    if (analysis.expiry_date) {
      const expiry = $('<div>')
        .addClass('anp-tile anp-expiry-tile')
        .text('Expiry: ' + analysis.expiry_date);
      container.append(expiry);
    } else {
      container.append(
        $('<div>')
          .addClass('anp-tile anp-expiry-tile')
          .text('No expiry date available')
      );
    }

    if (Array.isArray(analysis.flags) && analysis.flags.length) {
      analysis.flags.forEach(flag => {
        container.append(
          $('<div>')
            .addClass('anp-tile anp-flag-tile')
            .text(flag)
        );
      });
    }

    if (analysis.nutrition && typeof analysis.nutrition === 'object') {
      const names = {
        energy_kcal: 'Energy (kcal)',
        fat_g: 'Fat (g)',
        saturates_g: 'Saturates (g)',
        carbohydrate_g: 'Carb (g)',
        sugars_g: 'Sugars (g)',
        fiber_g: 'Fiber (g)',
        protein_g: 'Protein (g)',
        salt_g: 'Salt (g)'
      };
      let anyNut = false;
      Object.keys(names).forEach(key => {
        const val = analysis.nutrition[key];
        if (val == null) return;
        const lvl = classifyNutrient(key, val);
        const tile = $('<div>')
          .addClass('anp-tile anp-nutrition-tile');
        if (lvl) tile.addClass('anp-level-' + lvl);
        tile.text(names[key]);
        container.append(tile);
        anyNut = true;
      });
      if (!anyNut) {
        container.append(
          $('<div>')
            .addClass('anp-tile anp-nutrition-tile')
            .text('No nutrition data available')
        );
      }
    } else {
      container.append(
        $('<div>')
          .addClass('anp-tile anp-nutrition-tile')
          .text('No nutrition data available')
      );
    }

    if (analysis.summary) {
      const sum = analysis.summary.replace(/```[\s\S]*?```/g, '').trim();
      const tile = $('<div>').addClass('anp-tile anp-summary-tile');
      tile.append($('<strong>').text('Summary:'));
      tile.append($('<p>').text(sum));
      container.append(tile);
    }

    if (analysis.alternative) {
      const alt = analysis.alternative.replace(/```[\s\S]*?```/g, '').trim();
      const tile = $('<div>').addClass('anp-tile anp-alt-tile');
      tile.append($('<strong>').text('Alternative:'));
      tile.append($('<p>').text(alt));
      container.append(tile);
    }
  }
  // Expose helpers for testing and legacy support
  if (typeof window !== 'undefined') {
    window.classifyNutrient = classifyNutrient;
    window.sendScan = sendScan;
    window.sendToServer = sendToServer;
  }
  if (typeof module !== 'undefined') {
    module.exports = { classifyNutrient, sendScan, sendToServer };

  }
});

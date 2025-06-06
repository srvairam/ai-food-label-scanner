jQuery(function($){
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

      // 3) Convert to grayscale
      let imageData = ctx.getImageData(0, 0, w, h);
      let data = imageData.data;
      for (let i = 0; i < data.length; i += 4) {
        const r = data[i];
        const g = data[i + 1];
        const b = data[i + 2];
        const gray = 0.21 * r + 0.72 * g + 0.07 * b;
        data[i] = data[i + 1] = data[i + 2] = gray;
      }
      ctx.putImageData(imageData, 0, 0);

      // 4) Boost contrast (linear adjustment)
      imageData = ctx.getImageData(0, 0, w, h);
      data = imageData.data;
      const contrastFactor = 1.2; // reduced from 1.5 to avoid blowing out digits
      for (let i = 0; i < data.length; i += 4) {
        let v = data[i];
        v = (v - 128) * contrastFactor + 128;
        if (v < 0) v = 0;
        if (v > 255) v = 255;
        data[i] = data[i + 1] = data[i + 2] = v;
      }
      ctx.putImageData(imageData, 0, 0);

      // ───────────────────────────────────────────────────────────────
      // 5) OPTIONAL: Apply a very mild blur (box blur) to remove tiny specks
      //    This helps thresholding produce cleaner binary edges.
      //
      //    We’ll do a 3×3 box blur by averaging each pixel with its neighbors.
      const temp = ctx.getImageData(0, 0, w, h);
      const tempData = temp.data;
      const dst   = ctx.createImageData(w, h);
      const dstData = dst.data;
      for (let y = 1; y < h - 1; y++) {
        for (let x = 1; x < w - 1; x++) {
          let sum = 0;
          // sum the 3×3 block around (x,y)
          for (let dy = -1; dy <= 1; dy++) {
            for (let dx = -1; dx <= 1; dx++) {
              const idx = ((y + dy) * w + (x + dx)) * 4;
              sum += tempData[idx]; // red channel (all are gray)
            }
          }
          const avg = sum / 9;
          const destIdx = (y * w + x) * 4;
          dstData[destIdx] = dstData[destIdx + 1] = dstData[destIdx + 2] = avg;
          dstData[destIdx + 3] = tempData[destIdx + 3]; // keep alpha
        }
      }
      ctx.putImageData(dst, 0, 0);

      // ───────────────────────────────────────────────────────────────
      // 6) Binarize (thresholding) → black & white
      //    Lower threshold a bit to 120 so decimals/slashes survive.
      imageData = ctx.getImageData(0, 0, w, h);
      data = imageData.data;
      const threshold = 120; 
      for (let i = 0; i < data.length; i += 4) {
        const v = data[i]; // already grayscale
        const bw = v > threshold ? 255 : 0;
        data[i] = data[i + 1] = data[i + 2] = bw;
      }
      ctx.putImageData(imageData, 0, 0);

      // ───────────────────────────────────────────────────────────────
      // 7) OPTIONAL: Deskew (rotate so lines of text are horizontal)
      //    If you integrate a small Hough‐transform library, compute skewAngle here:
      //    const skewAngle = computeSkewAngle(canvas);
      //    if (Math.abs(skewAngle) > 0.5) {
      //      const deskewed = document.createElement('canvas');
      //      deskewed.width = w;
      //      deskewed.height = h;
      //      const dctx = deskewed.getContext('2d');
      //      dctx.translate(w/2, h/2);
      //      dctx.rotate(-skewAngle * Math.PI/180);
      //      dctx.drawImage(canvas, -w/2, -h/2);
      //      ctx.clearRect(0, 0, w, h);
      //      ctx.drawImage(deskewed, 0, 0);
      //    }

      // ───────────────────────────────────────────────────────────────
      // 8) (Debug) You can inspect the final B&W image by opening in a new tab:
      //    window.open(canvas.toDataURL(), '_blank');

      // 9) Export as JPEG (80% quality) and send to server
      const resizedDataUrl = canvas.toDataURL('image/jpeg', 0.8);
      // sendToServer(resizedDataUrl);
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

  function renderTiles(analysis) {
    const container = $('#anp-tiles').empty();

    if (analysis.expiry_date) {
      const expiry = $('<div>')
        .addClass('anp-tile anp-expiry-tile')
        .text('Expiry: ' + analysis.expiry_date);
      container.append(expiry);
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
      Object.keys(names).forEach(key => {
        const val = analysis.nutrition[key];
        if (val == null) return;
        const lvl = classifyNutrient(key, val);
        const tile = $('<div>')
          .addClass('anp-tile anp-nutrition-tile');
        if (lvl) tile.addClass('anp-level-' + lvl);
        tile.text(names[key] + ': ' + val);
        container.append(tile);
      });
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
});

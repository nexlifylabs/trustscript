/**
 * TrustScript Marquee Elementor Editor Panel Listener 
 */

(function() {
  'use strict';

  if (!window.elementor || !window.elementor.on) {
    return;
  }

  window.elementor.on('panel/open_editor/widget', function(panel) {
    var widgetType = panel.model.get('widgetType');
    
    if ('trustscript_reviews_showcase' !== widgetType) {
      return;
    }

    var settingsModel = panel.model.get('settings');

    settingsModel.on(
      'change:marquee_speed change:marquee_pause_hover change:marquee_gap change:marquee_card_width change:marquee_direction change:review_text_spacing',
      function() {
        var $preview = window.elementor.$previewContents;
        var widgetId = panel.model.get('id');
        var $widget  = $preview.find('[data-id="' + widgetId + '"]');
        var slider   = $widget.find('.trustscript-marquee-slider')[0];

        if (slider && window.trustscriptMarquee && window.trustscriptMarquee.init) {
          setTimeout(function() {
            var attrs = settingsModel.attributes;
            var config = {
              speed       : attrs.marquee_speed      || 32,
              pauseOnHover: 'yes' === attrs.marquee_pause_hover,
              gap         : attrs.marquee_gap         || 24,
              cardWidth   : attrs.marquee_card_width  || 320,
              direction   : attrs.marquee_direction   || 'left',
            };
            window.trustscriptMarquee.init(slider, config);
          }, 50);
        } 
      }
    );
  });
})();
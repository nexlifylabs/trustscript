/**
 * TrustScript Marquee Slider
 */

(function() {
  'use strict';

  function isElementorEditMode() {
    return (
      (window.elementorFrontend &&
        typeof window.elementorFrontend.isEditMode === 'function' &&
        window.elementorFrontend.isEditMode())
    );
  }

  function equalizeCardHeights(container) {
    const cards = container.querySelectorAll('.trustscript-marquee-card');
    if (cards.length === 0) return;

    cards.forEach(card => {
      card.style.height = 'auto';
    });

    let maxHeight = 0;
    cards.forEach(card => {
      const height = card.offsetHeight;
      if (height > maxHeight) {
        maxHeight = height;
      }
    });

    if (maxHeight > 0) {
      cards.forEach(card => {
        card.style.height = maxHeight + 'px';
      });
    }
  }

  function initMarqueeSlider(container, config) {
    const track = container.querySelector('.trustscript-marquee-track');
    if (!track) return;
    const speed = config.speed || 32;
    track.style.animationDuration = speed + 's';
    const direction = config.direction || 'left';
    container.setAttribute('data-direction', direction);
    const pauseOnHover = !!config.pauseOnHover;
    track.setAttribute('data-pause-on-hover', pauseOnHover ? 'true' : 'false');
    const oldEnter = container._marqueeEnter;
    const oldLeave = container._marqueeLeave;
    if (oldEnter) container.removeEventListener('mouseenter', oldEnter);
    if (oldLeave) container.removeEventListener('mouseleave', oldLeave);
    container._marqueeEnter = null;
    container._marqueeLeave = null;

    if (container._marqueeObserver) {
      container._marqueeObserver.disconnect();
      container._marqueeObserver = null;
    }

    if (container._resizeObserver) {
      container._resizeObserver.disconnect();
      container._resizeObserver = null;
    }

    track.style.animationPlayState = '';

    if (pauseOnHover) {

      if (isElementorEditMode()) {
        const enterHandler = function() {
          track.style.setProperty('animationPlayState', 'paused', 'important');
        };
        const leaveHandler = function() {
          track.style.setProperty('animationPlayState', 'running', 'important');
        };
        container.addEventListener('mouseenter', enterHandler);
        container.addEventListener('mouseleave', leaveHandler);
        container._marqueeEnter = enterHandler;
        container._marqueeLeave = leaveHandler;

      } else {
        const enterHandler = function() {
          track.style.setProperty('animationPlayState', 'paused', 'important');
        };
        const leaveHandler = function() {
          track.style.setProperty('animationPlayState', 'running', 'important');
        };
        container.addEventListener('mouseenter', enterHandler);
        container.addEventListener('mouseleave', leaveHandler);
        container._marqueeEnter = enterHandler;
        container._marqueeLeave = leaveHandler;
      }

    } else {
      track.style.animationPlayState = 'running';
    }

    requestAnimationFrame(function() {
      equalizeCardHeights(container);
    });

    if (typeof ResizeObserver !== 'undefined') {
      const cards = container.querySelectorAll('.trustscript-marquee-card');
      if (cards.length > 0) {
        let resizeTimeout;
        const resizeObserver = new ResizeObserver(function() {
          // Debounce to avoid excessive recalculations
          clearTimeout(resizeTimeout);
          resizeTimeout = setTimeout(function() {
            equalizeCardHeights(container);
          }, 200);
        });

        cards.forEach(card => {
          resizeObserver.observe(card);
        });

        container._resizeObserver = resizeObserver;
      }
    }
  }

  function initAll() {
    document.querySelectorAll('.trustscript-marquee-slider').forEach(function(slider) {
      const configAttr = slider.getAttribute('data-config');
      const config = configAttr ? JSON.parse(configAttr) : {};
      initMarqueeSlider(slider, config);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  window.trustscriptMarquee = {
    init: initMarqueeSlider,
    initAll: initAll
  };

  jQuery( window ).on( 'elementor/frontend/init', function() {
    if (!window.elementorFrontend || !window.elementorFrontend.hooks) {
      return;
    }
    elementorFrontend.hooks.addAction(
      'frontend/element_ready/trustscript_reviews_showcase.default',
      function($scope) {
        var slider = $scope.find('.trustscript-marquee-slider')[0];
        if (slider) {
          var configAttr = slider.getAttribute('data-config');
          var config = configAttr ? JSON.parse(configAttr) : {};
          initMarqueeSlider(slider, config);
        }
      }
    );
  } );

  jQuery( window ).on( 'elementor/preview/updated', function() {
    document.querySelectorAll('.trustscript-marquee-slider').forEach(function(slider) {
      var configAttr = slider.getAttribute('data-config');
      var config = configAttr ? JSON.parse(configAttr) : {};
      setTimeout(function() {
        equalizeCardHeights(slider);
      }, 100);
    });
  } );

})();
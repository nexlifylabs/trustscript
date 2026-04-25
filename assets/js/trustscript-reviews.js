/**
 * TrustScript Reviews - Product Page JS
 */
(function () {
  'use strict';

  var activeRating   = null;
  var activeKeyword  = 'all';
  var shownCount     = 10;
  var domLoadedCount = 0;
  var fetchInFlight  = false;
  var carouselOffset = {};
  var THUMB_W        = 88 + 8;
  var VISIBLE        = 3;
  var lbImages = [];
  var lbIndex  = 0;
  var lbIsGallery = false;
  var galleryTotal    = 0;
  var galleryLoaded   = 0;
  var galleryFetching = false;
  var GALLERY_PREFETCH_AT = 3;
  var GALLERY_FETCH_COUNT = 10;

  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.querySelector('.trustscript-reviews-wrap');

    if (wrap) {
      animateBars();
      initPagination();
      initVoting();
      initVerificationModal();
      initLightbox();
      initMediaGallery();
      initClickDelegation();
      return;
    }

    var nativeReviews = document.getElementById('reviews');
    var cfg = window.trustscript || {};
    
    if (!nativeReviews || !cfg.rest_url || !cfg.product_id) {
      return;
    }

    var restBase = (cfg.rest_url || '').replace(/\/$/, '');
    var url = restBase + '/trustscript/v1/reviews'
            + '?product_id=' + encodeURIComponent(cfg.product_id)
            + '&offset=0'
            + '&count=10';

    var headers = {'Content-Type': 'application/json'};
    if (cfg.wp_rest_nonce) headers['X-WP-Nonce'] = cfg.wp_rest_nonce;

    fetch(url, {
      method: 'GET',
      headers: headers
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data || !data.html) return;
      
      nativeReviews.innerHTML = data.html; // Safe: HTML is properly escaped server-side by PHP (esc_attr, esc_html, wp_kses_post)
      
      animateBars();
      initPagination();
      initVoting();
      initVerificationModal();
      initLightbox();
      initMediaGallery();
      initClickDelegation();
    })
    .catch(function() {
      // Silent fail — native WC output stays in place
    });
  });

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(updateGalleryOverflowResponsive, 150);
  });

  function animateBars() {
    setTimeout(function () {
      document.querySelectorAll('.trustscript-bar-fill').forEach(function (el) {
        el.style.width = (el.getAttribute('data-pct') || '0') + '%';
      });
      setTimeout(function () {
        document.querySelectorAll('.trustscript-sentiment-fill').forEach(function (el) {
          el.style.width = (el.getAttribute('data-pct') || '0') + '%';
        });
      }, 100);
    }, 120);
  }

  function filterRating(star) {
    activeRating = (activeRating === star) ? null : star;

    document.querySelectorAll('.trustscript-bar-row').forEach(function (row) {
      row.classList.toggle('trustscript-bar-active', parseInt(row.dataset.rating, 10) === activeRating);
    });

    shownCount = getPerPage();
    applyFilters();
  }

  function filterKeyword(kw) {
    activeKeyword = kw;
    document.querySelectorAll('.trustscript-chip').forEach(function (c) {
      c.classList.toggle('trustscript-chip-active', c.dataset.keyword === kw);
    });
    shownCount = getPerPage();
    applyFilters();
  }

  function sortReviews(mode, btn) {
    document.querySelectorAll('.trustscript-sort-btn').forEach(function (b) {
      b.classList.toggle('trustscript-sort-active', b === btn);
    });

    var list = document.getElementById('trustscript-reviews-list');
    if (!list) return;

    var wrappers = Array.from(list.querySelectorAll('.trustscript-review-card-wrapper'));
    wrappers.sort(function (a, b) {
      var cardA = a.querySelector('.trustscript-review-card');
      var cardB = b.querySelector('.trustscript-review-card');
      if (!cardA || !cardB) return 0;
      
      switch (mode) {
        case 'newest':  return parseInt(cardB.dataset.ts, 10)      - parseInt(cardA.dataset.ts, 10);
        case 'highest': return parseInt(cardB.dataset.rating, 10)  - parseInt(cardA.dataset.rating, 10);
        case 'lowest':  return parseInt(cardA.dataset.rating, 10)  - parseInt(cardB.dataset.rating, 10);
        default:        return parseInt(cardB.dataset.helpful, 10) - parseInt(cardA.dataset.helpful, 10);
      }
    });

    var frag = document.createDocumentFragment();
    wrappers.forEach(function (wrapper) { frag.appendChild(wrapper); });
    list.appendChild(frag);

    shownCount = getPerPage();
    applyFilters();
  }

  function initPagination() {
    var list = document.getElementById('trustscript-reviews-list');
    if (list) {
      shownCount     = getPerPage();
      domLoadedCount = parseInt(list.dataset.loaded, 10) || shownCount;
    }
    applyFilters();
  }

  function getPerPage() {
    var list = document.getElementById('trustscript-reviews-list');
    return list ? (parseInt(list.dataset.perPage, 10) || 10) : 10;
  }

  function getIncrement() {
    var list = document.getElementById('trustscript-reviews-list');
    return list ? (parseInt(list.dataset.increment, 10) || 5) : 5;
  }

  function loadMore() {
    var list = document.getElementById('trustscript-reviews-list');
    if (!list) return;

    var total     = parseInt(list.dataset.total, 10) || 0;
    var increment = getIncrement();

    shownCount += increment;
    applyFilters();

    if (domLoadedCount < total && !fetchInFlight) {
      fetchNextBatch(list, domLoadedCount, increment, total);
    }
  }

  function fetchNextBatch(list, offset, count, total) {
    var cfg       = window.trustscript || {};
    var productId = list.dataset.productId;
    var restBase  = (cfg.rest_url || '').replace(/\/$/, '');

    var url = restBase + '/trustscript/v1/reviews'
            + '?product_id=' + encodeURIComponent(productId)
            + '&offset='     + encodeURIComponent(offset)
            + '&count='      + encodeURIComponent(count);

    fetchInFlight = true;

    var headers = {'Content-Type': 'application/json'};
    if (cfg.wp_rest_nonce) headers['X-WP-Nonce'] = cfg.wp_rest_nonce;

    fetch(url, {method: 'GET', headers: headers})
      .then(function (r) { return r.json(); })
      .then(function (data) {
        fetchInFlight = false;
        if (!data || !data.html) return;

        var tmp = document.createElement('div');
        tmp.innerHTML = data.html;

        var frag = document.createDocumentFragment();
        var newCount = 0;
        while (tmp.firstChild) {
          frag.appendChild(tmp.firstChild);
          newCount++;
        }
        list.appendChild(frag);
        domLoadedCount += newCount;

        list.dataset.loaded = domLoadedCount;

        if (data.total && parseInt(data.total, 10) > 0) {
          list.dataset.total = data.total;
        }

        applyFilters();
      })
      .catch(function () {
        fetchInFlight = false;
      });
  }

  function applyFilters() {
    var wrappers = Array.from(document.querySelectorAll('#trustscript-reviews-list .trustscript-review-card-wrapper'));
    var cards = wrappers.map(function(w) { return w.querySelector('.trustscript-review-card'); }).filter(Boolean);

    var matched = [];
    wrappers.forEach(function (wrapper, idx) {
      var card = cards[idx];
      if (!card) return;
      
      var ratingOk  = (activeRating === null) || (parseInt(card.dataset.rating, 10) === activeRating);
      var kwData    = card.dataset.keywords || '';
      var keywordOk = (activeKeyword === 'all') || (kwData.indexOf(activeKeyword) !== -1);
      
      if (ratingOk && keywordOk) {
        matched.push(wrapper);
      } else {
        wrapper.classList.add('trustscript-hidden');
      }
    });

    matched.forEach(function (wrapper, i) {
      wrapper.classList.toggle('trustscript-hidden', i >= shownCount);
    });

    var loadMoreWrap = document.getElementById('trustscript-load-more-wrap');
    if (loadMoreWrap) {
      var list  = document.getElementById('trustscript-reviews-list');
      var total = list ? (parseInt(list.dataset.total, 10) || 0) : 0;
      var hasBuffered = matched.length > shownCount;
      var hasMore     = domLoadedCount < total;
      loadMoreWrap.style.display = (hasBuffered || hasMore) ? '' : 'none';
    }

    var countList    = document.getElementById('trustscript-reviews-list');
    var trueTotal    = countList ? (parseInt(countList.dataset.total, 10) || matched.length) : matched.length;
    var filterActive = (activeRating !== null) || (activeKeyword !== 'all');
    var displayTotal = filterActive ? matched.length : trueTotal;
    updateVisibleCount(Math.min(shownCount, matched.length), displayTotal);
  }

  function updateVisibleCount(visible, matched) {
    var badge   = document.getElementById('trustscript-visible-count');
    var empty   = document.getElementById('trustscript-empty-state');
    var strings = (window.trustscript && window.trustscript.strings) || {};

    function sprintf(template, args) {
      var result = template;
      for (var i = 0; i < args.length; i++) {
        result = result.replace('%' + (i + 1) + '\$d', args[i]).replace('%d', args[i]);
      }
      return result;
    }

    if (badge) {
      if (matched === 0) {
        badge.textContent = '';
      } else if (visible < matched) {
        var tpl = strings.showing_x_of_y || 'Showing %1$d of %2$d reviews';
        badge.textContent = sprintf(tpl, [visible, matched]);
      } else if (visible === 1) {
        badge.textContent = strings.showing_one || 'Showing 1 review';
      } else {
        var tplN = strings.showing_n || 'Showing %d reviews';
        badge.textContent = sprintf(tplN, [visible]);
      }
    }

    if (empty) {
      empty.style.display = (matched === 0) ? 'block' : 'none';
    }
  }

  function carouselMove(commentId, dir) {
    var vp = document.getElementById('trustscript-cvp-' + commentId);
    if (!vp) return;

    var thumbs = vp.querySelectorAll('.trustscript-carousel-thumb');
    var total  = thumbs.length;
    var maxOff = Math.max(0, total - VISIBLE);
    var cur    = carouselOffset[commentId] || 0;
    var next   = Math.min(Math.max(cur + dir, 0), maxOff);
    if (next === cur) return;

    carouselOffset[commentId] = next;

    Array.from(thumbs).forEach(function (el) {
      el.style.transform  = 'translateX(-' + (next * THUMB_W) + 'px)';
      el.style.transition = 'transform .25s cubic-bezier(.22,1,.36,1)';
    });

    var prevBtn = vp.parentElement.querySelector('.trustscript-carousel-prev');
    var nextBtn = vp.parentElement.querySelector('.trustscript-carousel-next');
    if (prevBtn) prevBtn.disabled = next === 0;
    if (nextBtn) nextBtn.disabled = next >= maxOff;
  }

  function initLightbox() {
    var overlay = document.getElementById('trustscript-lightbox');
    if (!overlay) return;

    document.getElementById('trustscript-lb-close').addEventListener('click', closeLightbox);
    document.getElementById('trustscript-lb-prev').addEventListener('click', function () {
      if (lbIndex > 0) showLbImage(lbIndex - 1);
    });
    document.getElementById('trustscript-lb-next').addEventListener('click', function () {
      if (lbIndex < lbImages.length - 1) showLbImage(lbIndex + 1);
    });

    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeLightbox();
    });

    document.addEventListener('keydown', function (e) {
      if (!overlay.classList.contains('trustscript-lb-open')) return;
      if (e.key === 'Escape')                                       closeLightbox();
      if (e.key === 'ArrowLeft'  && lbIndex > 0)                   showLbImage(lbIndex - 1);
      if (e.key === 'ArrowRight' && lbIndex < lbImages.length - 1) showLbImage(lbIndex + 1);
    });

    var touchStart = null;
    overlay.addEventListener('touchstart', function (e) { touchStart = e.touches[0].clientX; }, {passive: true});
    overlay.addEventListener('touchend', function (e) {
      if (touchStart === null) return;
      var dx = e.changedTouches[0].clientX - touchStart;
      if (Math.abs(dx) > 40) {
        if (dx < 0 && lbIndex < lbImages.length - 1) showLbImage(lbIndex + 1);
        if (dx > 0 && lbIndex > 0)                   showLbImage(lbIndex - 1);
      }
      touchStart = null;
    }, {passive: true});
  }

  function openLightbox(images, startIndex) {
    lbIsGallery = (images === window.trustscriptAllMedia);

    if (typeof images === 'string') {
      try { images = JSON.parse(images); } catch (e) { images = [images]; }
      lbIsGallery = false;
    }

    lbImages = lbIsGallery ? window.trustscriptAllMedia : (Array.isArray(images) ? images.slice() : [images]);
    lbIndex  = startIndex || 0;
    buildLbStrip();
    showLbImage(lbIndex, false);
    var overlay = document.getElementById('trustscript-lightbox');
    if (overlay) {
      overlay.classList.add('trustscript-lb-open');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeLightbox() {
    var overlay = document.getElementById('trustscript-lightbox');
    if (overlay) overlay.classList.remove('trustscript-lb-open');
    document.body.style.overflow = '';
    // Stop any playing video and clear src to stop network load
    var vid = document.getElementById('trustscript-lb-img');
    if (vid && vid.tagName === 'VIDEO') {
      vid.pause();
      vid.removeAttribute('src');
      vid.load();
    }
    var playBtn = document.getElementById('trustscript-lb-video-play');
    if (playBtn) playBtn.style.display = 'none';
  }

  function buildLbStrip() {
    var strip = document.getElementById('trustscript-lb-strip');
    if (!strip) return;
    if (lbImages.length <= 1) { strip.innerHTML = ''; return; }
    
    strip.innerHTML = '';
    lbImages.forEach(function (src, i) {
      var isVideo = /\.(mp4|webm|mov)$/i.test(src);
      var thumbDiv = document.createElement('div');
      thumbDiv.className = 'trustscript-lb-strip-thumb' + (i === lbIndex ? ' trustscript-lb-active' : '');
      thumbDiv.style.position = 'relative';
      thumbDiv.onclick = function() { showLbImage(i); };
      
      if (isVideo) {
        var vid = document.createElement('video');
        vid.src = src;
        vid.preload = 'metadata';
        vid.muted = true;
        vid.playsInline = true;
        vid.style.cssText = 'width:100%;height:100%;object-fit:cover;background:#111;pointer-events:none;';
        thumbDiv.appendChild(vid);
        var playOverlay = document.createElement('div');
        playOverlay.className = 'trustscript-video-thumb-overlay';
        playOverlay.innerHTML = '<svg viewBox="0 0 24 24" fill="white" width="16" height="16"><path d="M8 5v14l11-7z"/></svg>';
        thumbDiv.appendChild(playOverlay);
      } else {
        var img = document.createElement('img');
        img.src = src;  
        img.loading = 'lazy';
        img.alt = '';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover;';
        thumbDiv.appendChild(img);
      }
      
      strip.appendChild(thumbDiv);
    });
  }



  function createStars(rating) {
      var html = '';
      for (var s = 1; s <= 5; s++) {
          html += (s <= rating) ? '★' : '☆';
      }
      return html;
  }

  function showLbImage(idx, animate) {
      if (animate === undefined) animate = true;
      var prevIdx = lbIndex;
      lbIndex = idx;

      var imgContainer = document.getElementById('trustscript-lb-img');
      if (!imgContainer) return;

      var isVideo = /\.(mp4|webm|mov|avi|mkv|flv|m4v|wmv|ogv)$/i.test(lbImages[idx]);

      function setMediaSrc(src) {
          if (isVideo) {
              var video = imgContainer.tagName === 'VIDEO' ? imgContainer : null;
              if (!video) {
                  var newVideo = document.createElement('video');
                  newVideo.id = 'trustscript-lb-img';
                  newVideo.className = imgContainer.className;
                  newVideo.style.cssText = imgContainer.style.cssText;
                  newVideo.controls = false;
                  newVideo.controlsList = 'nodownload';
                  newVideo.preload = 'metadata';
                  imgContainer.parentNode.replaceChild(newVideo, imgContainer);
                  video = newVideo;
                  imgContainer = video;
              }
              video.controls = false;
              video.preload = 'metadata';
              video.src = src;

              var playBtn = document.getElementById('trustscript-lb-video-play');
              if (playBtn) {
                  playBtn.style.display = 'flex';
                  playBtn.onclick = function () {
                      playBtn.style.display = 'none';
                      video.controls = true;
                      video.play();
                  };
              }
          } else {
              var playBtn = document.getElementById('trustscript-lb-video-play');
              if (playBtn) playBtn.style.display = 'none';

              var img = imgContainer.tagName === 'IMG' ? imgContainer : null;
              if (!img) {
                  var newImg = document.createElement('img');
                  newImg.id = 'trustscript-lb-img';
                  newImg.className = imgContainer.className;
                  newImg.style.cssText = imgContainer.style.cssText;
                  imgContainer.parentNode.replaceChild(newImg, imgContainer);
                  img = newImg;
                  imgContainer = img;
              }
              img.onerror = function () {
                  img.onerror = null;
                  var direction = idx > prevIdx ? 1 : idx < prevIdx ? -1 : 1;
                  var next = idx + direction;
                  
                  if (next >= 0 && next < lbImages.length) {
                      showLbImage(next, false);
                  } else {
                      img.removeAttribute('src');
                      var counter = document.getElementById('trustscript-lb-counter');
                      var unavailableMsg = (window.trustscript && window.trustscript.strings && window.trustscript.strings.imageUnavailable) || 'Image unavailable';
                      if (counter) counter.textContent = unavailableMsg;
                  }
              };
              img.src = src;
          }
      }

      if (animate) {
          imgContainer.classList.add('trustscript-lb-fading');
          setTimeout(function () {
              setMediaSrc(lbImages[idx]);
              imgContainer.classList.remove('trustscript-lb-fading');
          }, 180);
      } else {
          setMediaSrc(lbImages[idx]);
      }

      var counter = document.getElementById('trustscript-lb-counter');
      if (counter) counter.textContent = lbImages.length > 1 ? (idx + 1) + ' / ' + lbImages.length : '';

      var strip = document.getElementById('trustscript-lb-strip');
      if (strip) {
          strip.querySelectorAll('.trustscript-lb-strip-thumb').forEach(function (el, i) {
              el.classList.toggle('trustscript-lb-active', i === idx);
          });
          
          var active = strip.querySelector('.trustscript-lb-strip-thumb.trustscript-lb-active');
          if (active) {
              var thumbLeft   = active.offsetLeft;
              var thumbWidth  = active.offsetWidth;
              var stripWidth  = strip.offsetWidth;
              var scrollTo    = thumbLeft - (stripWidth / 2) + (thumbWidth / 2);
              strip.scrollTo({ left: scrollTo, behavior: 'smooth' });
          }

      }

      var prev = document.getElementById('trustscript-lb-prev');
      var next = document.getElementById('trustscript-lb-next');
      if (prev) prev.disabled = idx === 0;
      if (next) next.disabled = idx === lbImages.length - 1;

      var infoPanel = document.getElementById('trustscript-lb-info');
      var meta = (lbIsGallery && window.trustscriptAllMediaMeta) ? window.trustscriptAllMediaMeta[idx] : null;

      if (meta && infoPanel) {
          var dateEl = document.getElementById('trustscript-lb-info-date');
          if (dateEl) {
              if (meta.ts) {
                  var d = new Date(meta.ts * 1000);
                  dateEl.textContent = d.toLocaleDateString(undefined, {year:'numeric', month:'long', day:'numeric'});
              } else if (meta.date) {
                  dateEl.textContent = meta.date;
              }
          }

          var authorEl = document.getElementById('trustscript-lb-info-author');
          if (authorEl) authorEl.textContent = meta.author || '';

          var starsEl = document.getElementById('trustscript-lb-info-stars');
          if (starsEl) starsEl.innerHTML = createStars(meta.rating || 0);

          var verifiedEl = document.getElementById('trustscript-lb-info-verified');
          if (verifiedEl) verifiedEl.style.display = meta.verified ? 'inline-flex' : 'none';

          var kwEl = document.getElementById('trustscript-lb-info-keywords');
          if (kwEl) {
              if (meta.keywords && meta.keywords.trim()) {
                  kwEl.innerHTML = '';
                  meta.keywords.split(',').filter(Boolean).forEach(function(k) {
                      var keyword = k.trim().charAt(0).toUpperCase() + k.trim().slice(1);
                      var span = document.createElement('span');
                      span.className = 'trustscript-keyword-tag';
                      span.textContent = '[ ' + keyword + ' ]';
                      kwEl.appendChild(span);
                  });
                  kwEl.style.display = '';
              } else {
                  kwEl.style.display = 'none';
              }
          }

          var helpfulEl = document.getElementById('trustscript-lb-info-helpful');
          if (helpfulEl) {
              if (meta.helpful > 0) {
                  helpfulEl.textContent = '👍 ' + meta.helpful + ' helpful';
                  helpfulEl.style.display = '';
              } else {
                  helpfulEl.style.display = 'none';
              }
          }

          infoPanel.style.display = '';
      } else if (infoPanel) {
          infoPanel.style.display = 'none';
      }

      if (lbIsGallery && galleryLoaded < galleryTotal && !galleryFetching) {
          var distFromEnd = lbImages.length - 1 - idx;
          if (distFromEnd <= GALLERY_PREFETCH_AT) {
              fetchMoreGalleryImages();
          }
      }
  }

  function initVoting() {
    var cfg  = window.trustscript || {};
    var list = document.getElementById('trustscript-reviews-list');
    if (!cfg.rest_url || !list) return;

    list.addEventListener('click', function (e) {
      var btn = e.target.closest('.trustscript-helpful-btn');
      if (!btn) return;

      if (!cfg.is_logged_in) {
        e.preventDefault();
        var commentId = btn.dataset.commentId;
        var msgEl = document.getElementById('trustscript-msg-' + commentId);
        var loginMsg = (cfg.strings && cfg.strings.loginToVote) || 'Login to vote';
        showVoteMsg(msgEl, loginMsg, 'error');
        return;
      }

      if (btn.disabled) return;

      e.preventDefault();
      var commentId = btn.dataset.commentId;
      var voteType  = btn.dataset.voteType;
      if (commentId && voteType) sendVote(commentId, voteType);
    });
  }

  function sendVote(commentId, voteType) {
    var cfg = window.trustscript || {};

    var upBtn  = document.querySelector('[data-comment-id="' + commentId + '"][data-vote-type="upvote"]');
    var dnBtn  = document.querySelector('[data-comment-id="' + commentId + '"][data-vote-type="downvote"]');
    var upSpan = document.getElementById('trustscript-up-' + commentId);
    var dnSpan = document.getElementById('trustscript-dn-' + commentId);
    var msgEl  = document.getElementById('trustscript-msg-' + commentId);
    var prevUpText = upSpan  ? upSpan.textContent.trim()  : '0';
    var prevDnText = dnSpan  ? dnSpan.textContent.trim()  : '0';
    var prevUpVal  = parseInt(prevUpText, 10) || 0;
    var prevDnVal  = parseInt(prevDnText, 10) || 0;

    if (voteType === 'upvote') {
      if (upSpan) upSpan.textContent = prevUpVal + 1;
      if (upBtn)  { upBtn.classList.add('trustscript-voted-up');   upBtn.disabled = true; }
      if (dnBtn)  dnBtn.disabled = true;
    } else {
      if (dnSpan) dnSpan.textContent = prevDnVal + 1;
      if (dnBtn)  { dnBtn.classList.add('trustscript-voted-down'); dnBtn.disabled = true; }
      if (upBtn)  upBtn.disabled = true;
    }

    showVoteMsg(msgEl, (window.trustscript && window.trustscript.strings && window.trustscript.strings.vote_thanks) || 'Thank you!', 'success');

    var headers = {'Content-Type': 'application/json'};
    if (cfg.wp_rest_nonce) headers['X-WP-Nonce'] = cfg.wp_rest_nonce;

    fetch(cfg.rest_url + 'trustscript/v1/vote', {
      method:  'POST',
      headers: headers,
      body:    JSON.stringify({comment_id: parseInt(commentId, 10), vote_type: voteType}),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.success) {
        if (upSpan) upSpan.textContent = (data.upvotes   > 0) ? data.upvotes   : '';
        if (dnSpan) dnSpan.textContent = (data.downvotes > 0) ? data.downvotes : '';

        if (voteType === 'upvote') {
          if (upBtn) { upBtn.classList.add('trustscript-voted-up');     upBtn.disabled = false; }
          if (dnBtn) { dnBtn.classList.remove('trustscript-voted-down'); dnBtn.disabled = false; }
        } else {
          if (dnBtn) { dnBtn.classList.add('trustscript-voted-down');   dnBtn.disabled = false; }
          if (upBtn) { upBtn.classList.remove('trustscript-voted-up');   upBtn.disabled = false; }
        }
      } else {
        rollbackVote(commentId, voteType, prevUpText, prevDnText, upBtn, dnBtn, upSpan, dnSpan);
        var errMsg = (data && data.message) ? data.message
          : ((window.trustscript && window.trustscript.strings && window.trustscript.strings.vote_error) || 'Could not save vote.');
        showVoteMsg(msgEl, errMsg, 'error');
      }
    })
    .catch(function () {
      rollbackVote(commentId, voteType, prevUpText, prevDnText, upBtn, dnBtn, upSpan, dnSpan);
      showVoteMsg(msgEl,
        (window.trustscript && window.trustscript.strings && window.trustscript.strings.vote_error) || 'Could not save vote.',
        'error'
      );
    });
  }

  function rollbackVote(commentId, voteType, prevUpText, prevDnText, upBtn, dnBtn, upSpan, dnSpan) {
    if (upSpan) upSpan.textContent = prevUpText;
    if (dnSpan) dnSpan.textContent = prevDnText;
    if (upBtn)  { upBtn.classList.remove('trustscript-voted-up');   upBtn.disabled = false; }
    if (dnBtn)  { dnBtn.classList.remove('trustscript-voted-down'); dnBtn.disabled = false; }
  }

  function showVoteMsg(el, text, type) {
    if (!el) return;
    el.textContent = text;
    el.className   = 'trustscript-vote-msg trustscript-msg-' + type;
    void el.offsetWidth;
    el.classList.add('trustscript-msg-visible');
    setTimeout(function () {
      el.classList.remove('trustscript-msg-visible');
    }, 3000);
  }

  function initVerificationModal() {
    var modal    = document.getElementById('trustscript-verify-modal');
    if (!modal) return;

    var hashEl    = document.getElementById('trustscript-modal-hash');
    var authorEl  = document.getElementById('trustscript-modal-author');
    var starsEl   = document.getElementById('trustscript-modal-stars');
    var reviewerEl = document.getElementById('trustscript-modal-reviewer');
    var copyBtn   = document.getElementById('trustscript-copy-hash');
    var linkBtn   = document.getElementById('trustscript-verify-link-btn');
    var closeBtn  = modal.querySelector('.trustscript-modal-close');

    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.trustscript-verify-link');
      if (!btn) return;

      e.preventDefault();
      var hash      = btn.getAttribute('data-hash');
      var verifyUrl = btn.getAttribute('data-verify-url');
      var author    = btn.getAttribute('data-author') || '';
      var rating    = parseInt(btn.getAttribute('data-rating'), 10) || 0;

      if (hash && hashEl) {
        hashEl.textContent = hash;

        // Populate reviewer identity row
        if (authorEl) authorEl.textContent = author;
        if (starsEl) {
          var stars = '';
          for (var s = 1; s <= 5; s++) { stars += s <= rating ? '★' : '☆'; }
          starsEl.textContent = stars;
        }
        if (reviewerEl) reviewerEl.style.display = author ? '' : 'none';

        modal.style.display = '';
        modal.classList.add('active');

        if (linkBtn && verifyUrl) {
          linkBtn.href = verifyUrl;
          try { sessionStorage.setItem('trustscript_verify_hash', hash); } catch (e) {}
        }
      }
    });

    if (copyBtn && hashEl) {
      copyBtn.addEventListener('click', function () {
        var hash = hashEl.textContent;
        var btn  = copyBtn;
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(hash).then(function () {
            flashCopyBtn(btn);
          }).catch(function () { fallbackCopy(hash, btn); });
        } else {
          fallbackCopy(hash, btn);
        }
      });
    }

    function flashCopyBtn(btn) {
      var copiedMsg = (window.trustscript && window.trustscript.strings && window.trustscript.strings.copied) || '✓ Copied!';
      var copyMsg   = (window.trustscript && window.trustscript.strings && window.trustscript.strings.copyHash) || 'Copy Hash';
      btn.textContent = copiedMsg;
      btn.classList.add('copied');
      setTimeout(function () {
        btn.textContent = copyMsg;
        btn.classList.remove('copied');
      }, 2000);
    }

    function fallbackCopy(text, btn) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.cssText = 'position:fixed;left:-9999px';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); flashCopyBtn(btn); } catch (e) {}
      document.body.removeChild(ta);
    }

    function closeModal() {
      modal.classList.remove('active');
    }

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });
  }

  function initMediaGallery() {
    var galleryCard = document.querySelector('[data-media]');
    if (galleryCard) {
      try {
        window.trustscriptAllMedia     = JSON.parse(galleryCard.getAttribute('data-media') || '[]');
        window.trustscriptAllMediaMeta = JSON.parse(galleryCard.getAttribute('data-meta')  || '[]');
        galleryTotal  = parseInt(galleryCard.getAttribute('data-total'), 10) || window.trustscriptAllMedia.length;
        galleryLoaded = window.trustscriptAllMedia.length;
      } catch (e) {
        window.trustscriptAllMedia     = [];
        window.trustscriptAllMediaMeta = [];
        galleryLoaded = 0;
      }
    } else {
      window.trustscriptAllMedia     = [];
      window.trustscriptAllMediaMeta = [];
      galleryLoaded = 0;
    }
    updateGalleryOverflowResponsive();
  }

  function fetchMoreGalleryImages() {
    if (galleryFetching || galleryLoaded >= galleryTotal) return;

    var cfg         = window.trustscript || {};
    var restBase    = (cfg.rest_url || '').replace(/\/$/, '');
    var galleryCard = document.querySelector('[data-media]');
    var productId   = galleryCard ? galleryCard.getAttribute('data-product-id') : '';

    if (!restBase || !productId) return;

    galleryFetching = true;

    var url = restBase + '/trustscript/v1/gallery'
            + '?product_id=' + encodeURIComponent(productId)
            + '&offset='     + encodeURIComponent(galleryLoaded)
            + '&count='      + encodeURIComponent(GALLERY_FETCH_COUNT);

    var headers = {'Content-Type': 'application/json'};
    if (cfg.wp_rest_nonce) headers['X-WP-Nonce'] = cfg.wp_rest_nonce;

    fetch(url, {method: 'GET', headers: headers})
      .then(function (r) { return r.json(); })
      .then(function (data) {
        galleryFetching = false;
        if (!data || !Array.isArray(data.urls) || data.urls.length === 0) return;

        data.urls.forEach(function (url) {
          window.trustscriptAllMedia.push(url);
        });
        if (Array.isArray(data.meta)) {
          if (!window.trustscriptAllMediaMeta) window.trustscriptAllMediaMeta = [];
          data.meta.forEach(function (m) {
            window.trustscriptAllMediaMeta.push(m);
          });
        }

        galleryLoaded += data.urls.length;

        if (data.total && data.total > galleryTotal) {
          galleryTotal = data.total;
        }

        var overlay = document.getElementById('trustscript-lightbox');
        if (overlay && overlay.classList.contains('trustscript-lb-open') && lbIsGallery) {
          buildLbStrip();
          var prev = document.getElementById('trustscript-lb-prev');
          var next = document.getElementById('trustscript-lb-next');
          if (prev) prev.disabled = lbIndex === 0;
          if (next) next.disabled = lbIndex === lbImages.length - 1;
        }
      })
      .catch(function () {
        galleryFetching = false;
      });
  }

  function updateGalleryOverflowResponsive() {
    var galleryGrid = document.querySelector('.trustscript-gallery-grid');
    if (!galleryGrid) return;
    
    var isMobile = window.innerWidth <= 540;
    var items = galleryGrid.querySelectorAll('[data-gallery-lightbox]');
    
    if (isMobile && items.length === 5) {
      galleryGrid.classList.add('trustscript-mobile-layout');
      
      var fourthItem = items[3];
      var fifthItem = items[4];
      
      if (fourthItem && fifthItem) {
        fourthItem.classList.add('trustscript-gallery-overflow-thumb');
        
        if (!fourthItem.hasAttribute('data-original-index')) {
          fourthItem.setAttribute('data-original-index', fourthItem.getAttribute('data-lightbox-index'));
          fourthItem.setAttribute('data-lightbox-index', fifthItem.getAttribute('data-lightbox-index'));
        }
        
        if (!fourthItem.querySelector('.trustscript-gallery-overlay')) {
          var fifthOverlay = fifthItem.querySelector('.trustscript-gallery-overlay');
          if (fifthOverlay) {
            var overlayClone = fifthOverlay.cloneNode(true);
            fourthItem.appendChild(overlayClone);
          }
        }
      }
    } else if (!isMobile && items.length >= 5) {
      galleryGrid.classList.remove('trustscript-mobile-layout');
      
      var fourthDesktop = items[3];
      
      if (fourthDesktop) {
        fourthDesktop.classList.remove('trustscript-gallery-overflow-thumb');
        
        if (fourthDesktop.hasAttribute('data-original-index')) {
          fourthDesktop.setAttribute('data-lightbox-index', fourthDesktop.getAttribute('data-original-index'));
          fourthDesktop.removeAttribute('data-original-index');
        }
        
        var overlay = fourthDesktop.querySelector('.trustscript-gallery-overlay');
        if (overlay) overlay.remove();
      }
    }
  }

  function initClickDelegation() {
    document.addEventListener('click', function (e) {
      // --- Filter chips (keyword) ---
      var chip = e.target.closest('.trustscript-chip');
      if (chip && chip.dataset.keyword !== undefined) {
        filterKeyword(chip.dataset.keyword);
        return;
      }

      // --- Sort buttons ---
      var sortBtn = e.target.closest('.trustscript-sort-btn');
      if (sortBtn && sortBtn.dataset.sort) {
        sortReviews(sortBtn.dataset.sort, sortBtn);
        return;
      }

      // --- Load more ---
      if (e.target.closest('.trustscript-load-more-btn')) {
        loadMore();
        return;
      }

      // --- Carousel arrows ---
      var arrow = e.target.closest('.trustscript-carousel-arrow');
      if (arrow && arrow.dataset.carouselId) {
        carouselMove(arrow.dataset.carouselId, parseInt(arrow.dataset.dir, 10));
        return;
      }

      // --- Carousel thumbs (open lightbox from review card) ---
      var carouselThumb = e.target.closest('.trustscript-carousel-thumb');
      if (carouselThumb) {
        var carousel = carouselThumb.closest('.trustscript-photo-carousel');
        if (carousel) {
          var images = [];
          try { images = JSON.parse(carousel.dataset.images || '[]'); } catch (err) { /* noop */ }
          var idx = parseInt(carouselThumb.dataset.lightboxIndex, 10) || 0;
          openLightbox(images, idx);
        }
        return;
      }

      // --- Gallery thumbs (open lightbox from media gallery card) ---
      var galleryThumb = e.target.closest('[data-gallery-lightbox]');
      if (galleryThumb) {
        var gIdx = parseInt(galleryThumb.dataset.lightboxIndex, 10) || 0;
        openLightbox(window.trustscriptAllMedia, gIdx);
        return;
      }

      // --- Rating bar rows ---
      var barRow = e.target.closest('.trustscript-bar-row');
      if (barRow && barRow.dataset.rating) {
        filterRating(parseInt(barRow.dataset.rating, 10));
        return;
      }
    });
  }

})();
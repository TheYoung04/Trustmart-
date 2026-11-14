// TrustMart landing — scroll reveal + smooth anchor offset
(function () {
  'use strict';

  // Reveal sections/cards as they enter the viewport
  var targets = document.querySelectorAll('.feat, .step, .gallery figure, .trust-card, .ai-panel, .score-list li');
  targets.forEach(function (el) { el.classList.add('reveal'); });

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12 });
    targets.forEach(function (el) { io.observe(el); });
  } else {
    targets.forEach(function (el) { el.classList.add('in'); });
  }
})();
/* THE MOVE — interakce a animace */

(function () {
  "use strict";

  /* ---------- Navigace: pozadí + skrývání při scrollu ---------- */
  var nav = document.querySelector(".nav");
  var lastY = window.scrollY;

  function onScroll() {
    var y = window.scrollY;
    nav.classList.toggle("is-scrolled", y > 40);
    // skryj při scrollu dolů, ukaž při scrollu nahoru
    if (y > 300 && y > lastY + 6) {
      nav.classList.add("is-hidden");
    } else if (y < lastY - 6) {
      nav.classList.remove("is-hidden");
    }
    lastY = y;
  }

  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- Mobilní menu ---------- */
  var burger = document.querySelector(".nav-burger");
  var menu = document.querySelector(".nav-menu");

  if (burger && menu) {
    burger.addEventListener("click", function () {
      var open = menu.classList.toggle("is-open");
      burger.classList.toggle("is-open", open);
      burger.setAttribute("aria-expanded", open ? "true" : "false");
      document.body.style.overflow = open ? "hidden" : "";
    });

    menu.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        menu.classList.remove("is-open");
        burger.classList.remove("is-open");
        document.body.style.overflow = "";
      });
    });
  }

  /* ---------- Odkrývání prvků při scrollu ---------- */
  var revealables = document.querySelectorAll(".reveal, .mask-line, .curve-svg");

  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            io.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: "0px 0px -5% 0px" }
    );
    revealables.forEach(function (el) { io.observe(el); });
  } else {
    revealables.forEach(function (el) { el.classList.add("is-visible"); });
  }

  /* Délka tahu pro kreslení křivek */
  document.querySelectorAll(".curve-svg path").forEach(function (path) {
    try {
      var len = Math.ceil(path.getTotalLength());
      var svg = path.closest(".curve-svg");
      svg.style.setProperty("--curve-len", len);
      // zápornou délku předáváme jako hotovou hodnotu — calc(var() * -1)
      // se v keyframes neinterpoluje a animace by místo plynulého
      // odtečení linky jen skočila do neviditelna
      svg.style.setProperty("--curve-len-neg", -len);
    } catch (e) { /* SVG bez rozměrů — ponech výchozí */ }
  });

  /* ---------- FAQ akordeon ---------- */
  document.querySelectorAll(".faq-item").forEach(function (item) {
    var btn = item.querySelector(".faq-question");
    var answer = item.querySelector(".faq-answer");

    btn.addEventListener("click", function () {
      var isOpen = item.classList.contains("is-open");

      // zavři ostatní ve stejném seznamu
      item.parentElement.querySelectorAll(".faq-item.is-open").forEach(function (other) {
        other.classList.remove("is-open");
        other.querySelector(".faq-answer").style.maxHeight = null;
        other.querySelector(".faq-question").setAttribute("aria-expanded", "false");
      });

      if (!isOpen) {
        item.classList.add("is-open");
        answer.style.maxHeight = answer.scrollHeight + "px";
        btn.setAttribute("aria-expanded", "true");
      }
    });
  });

  /* ---------- Termíny lekcí (načítání z API) ---------- */
  var scheduleList = document.getElementById("schedule-list");
  var modal = document.getElementById("booking-modal");

  // Náhled na GitHub Pages: statický hosting bez PHP — ukázková data,
  // rezervace se jen simulují. Na ostrém hostingu se tato větev nepoužije.
  var IS_PREVIEW = /\.github\.io$/.test(location.hostname);

  function renderSchedule(terminy) {
    scheduleList.innerHTML = "";

    if (!terminy.length) {
      scheduleList.innerHTML =
        '<p class="schedule-loading text-grey">Momentálně nevypisujeme žádné termíny. ' +
        'Napište nám a dáme vám vědět o nejbližší lekci.</p>';
      return;
    }

    terminy.forEach(function (t) {
      var row = document.createElement("div");
      row.className = "schedule-row";

      var free;
      if (t.volno === 0) {
        free = '<span class="schedule-free schedule-free--full">Obsazeno</span>';
      } else if (t.volno <= 2) {
        free = '<span class="schedule-free schedule-free--low">Poslední ' +
          t.volno + " " + (t.volno === 1 ? "volné místo" : "volná místa") +
          " z " + t.kapacita + "</span>";
      } else {
        free = '<span class="schedule-free">Volno ' + t.volno + " z " + t.kapacita + "</span>";
      }

      row.innerHTML =
        '<span class="schedule-time">' + t.den + " " + t.datum + " · " + t.cas_od + " – " + t.cas_do + "</span>" +
        '<span class="schedule-place">' + escapeHtml(t.misto) +
        (t.poznamka ? ' <small class="text-grey">' + escapeHtml(t.poznamka) + "</small>" : "") + "</span>" +
        free +
        (t.volno > 0
          ? '<button type="button" class="button" data-book="' + t.id + '">Rezervovat místo</button>'
          : '<a href="#kontakt" class="button" style="opacity:.5">Napsat si o místo</a>');

      if (t.volno > 0) {
        row.querySelector("[data-book]").addEventListener("click", function () {
          openBooking(t);
        });
      }

      scheduleList.appendChild(row);
    });
  }

  function escapeHtml(s) {
    var div = document.createElement("div");
    div.textContent = s == null ? "" : String(s);
    return div.innerHTML;
  }

  if (scheduleList) {
    var apiUrl = IS_PREVIEW ? "api/terminy-demo.json" : scheduleList.dataset.api;
    fetch(apiUrl, { cache: "no-store" })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          renderSchedule(data.terminy);
          if (IS_PREVIEW) {
            var note = document.createElement("p");
            note.className = "schedule-loading text-grey text-small";
            note.textContent = "Náhled webu — termíny jsou ukázkové, rezervace se neukládají.";
            scheduleList.appendChild(note);
          }
        }
        else { throw new Error(); }
      })
      .catch(function () {
        scheduleList.innerHTML =
          '<p class="schedule-loading text-grey">Termíny se nepodařilo načíst. ' +
          'Napište nám na <a href="mailto:info@themove.cz" style="text-decoration:underline">info@themove.cz</a> ' +
          "a rádi vám volná místa pošleme.</p>";
      });
  }

  /* ---------- Rezervační okno ---------- */
  function openBooking(t) {
    if (!modal) { return; }
    modal.hidden = false;
    document.body.style.overflow = "hidden";

    modal.querySelector(".modal-lesson").textContent =
      t.den + " " + t.datum + " · " + t.cas_od + " – " + t.cas_do + " · " + t.misto;
    modal.querySelector('[name="termin_id"]').value = t.id;

    var form = modal.querySelector(".booking-form");
    var success = modal.querySelector(".booking-success");
    form.hidden = false;
    form.reset();
    modal.querySelector('[name="termin_id"]').value = t.id;
    success.hidden = true;
    modal.querySelector(".booking-error").hidden = true;
    modal.querySelector("#b-name").focus();
  }

  function closeBooking() {
    if (!modal) { return; }
    modal.hidden = true;
    document.body.style.overflow = "";
  }

  if (modal) {
    modal.querySelectorAll("[data-close]").forEach(function (el) {
      el.addEventListener("click", closeBooking);
    });

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !modal.hidden) { closeBooking(); }
    });

    var bookingForm = modal.querySelector(".booking-form");

    bookingForm.addEventListener("submit", function (e) {
      e.preventDefault();

      var errorBox = modal.querySelector(".booking-error");
      errorBox.hidden = true;

      var payload = {
        termin_id: bookingForm.termin_id.value,
        jmeno: bookingForm.jmeno.value.trim(),
        email: bookingForm.email.value.trim(),
        telefon: bookingForm.telefon.value.trim(),
        web: bookingForm.web.value
      };

      if (!payload.jmeno || !payload.email) {
        errorBox.textContent = "Vyplňte prosím jméno a e-mail.";
        errorBox.hidden = false;
        return;
      }

      var submit = bookingForm.querySelector('[type="submit"]');
      submit.disabled = true;

      if (IS_PREVIEW) {
        submit.disabled = false;
        bookingForm.hidden = true;
        var previewSuccess = modal.querySelector(".booking-success");
        previewSuccess.querySelector("p").textContent =
          "Toto je náhled webu — rezervace se zatím neukládají. Na ostrém webu by teď bylo místo rezervované.";
        previewSuccess.hidden = false;
        return;
      }

      fetch("api/rezervace.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          submit.disabled = false;
          if (data && data.ok) {
            bookingForm.hidden = true;
            var success = modal.querySelector(".booking-success");
            success.querySelector("p").textContent = data.zprava;
            success.hidden = false;
            // obnov počty volných míst
            fetch(scheduleList.dataset.api, { cache: "no-store" })
              .then(function (r) { return r.json(); })
              .then(function (d) { if (d && d.ok) { renderSchedule(d.terminy); } })
              .catch(function () {});
          } else {
            errorBox.textContent = (data && data.zprava) || "Rezervaci se nepodařilo odeslat.";
            errorBox.hidden = false;
          }
        })
        .catch(function () {
          submit.disabled = false;
          errorBox.textContent = "Rezervaci se nepodařilo odeslat. Zkuste to prosím znovu.";
          errorBox.hidden = false;
        });
    });
  }

  /* ---------- Příběhy (roztahovací video karty) ---------- */
  var storiesEl = document.getElementById("stories");

  if (storiesEl) {
    var storyItems = storiesEl.querySelectorAll(".story");

    var closeStory = function (story) {
      story.classList.remove("is-open");
      storiesEl.classList.remove("has-open");
      var v = story.querySelector("video");
      v.pause();
    };

    var openStory = function (story) {
      storyItems.forEach(function (other) {
        if (other !== story && other.classList.contains("is-open")) {
          other.classList.remove("is-open");
          other.querySelector("video").pause();
        }
      });
      storiesEl.classList.add("has-open");
      story.classList.add("is-open");
      var v = story.querySelector("video");
      // krátká prodleva, ať se karta stihne roztáhnout
      setTimeout(function () {
        v.play().catch(function () {});
      }, 400);
    };

    storyItems.forEach(function (story) {
      story.addEventListener("click", function () {
        if (!story.classList.contains("is-open")) { openStory(story); }
      });

      story.querySelector(".story-close").addEventListener("click", function (e) {
        e.stopPropagation();
        closeStory(story);
      });
    });
  }

  /* ---------- Kontaktní formulář (mailto) ---------- */
  var form = document.querySelector(".form");

  if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();

      var check = form.querySelector("[data-check]");
      if (check && parseInt(check.value, 10) !== parseInt(check.dataset.check, 10)) {
        check.style.borderBottomColor = "#d0342c";
        check.focus();
        return;
      }

      var name = form.querySelector('[name="name"]').value.trim();
      var email = form.querySelector('[name="email"]').value.trim();
      var message = form.querySelector('[name="message"]').value.trim();

      var body = message + "\n\n" + name + "\n" + email;
      window.location.href =
        "mailto:info@themove.cz" +
        "?subject=" + encodeURIComponent("Poptávka z webu — The Move") +
        "&body=" + encodeURIComponent(body);
    });
  }
})();

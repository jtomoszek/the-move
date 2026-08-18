/* THE MOVE :: interakce a animace */

(function () {
  "use strict";

  /* ---------- Navigace ----------
     Menu zůstává na stránce trvale. Po odscrollování od začátku
     mu jen podložíme pozadí, aby text pod ním zůstal čitelný. */
  var nav = document.querySelector(".nav");

  function onScroll() {
    nav.classList.toggle("is-scrolled", window.scrollY > 40);
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

  /* ---------- Postupné nadpisy: písmena z bluru, jak se čte ----------
     Každé písmeno hero nadpisu se rozdělí do vlastního spanu a při
     odkrytí najíždí zespodu z rozostření do ostrosti, se zpožděním
     rostoucím zleva doprava, jako by se nadpis četl. */
  var ltrHeadings = [];
  document.querySelectorAll(".mask-line").forEach(function (line) {
    var h = line.parentElement;
    if (ltrHeadings.indexOf(h) === -1) { ltrHeadings.push(h); }
  });

  ltrHeadings.forEach(function (heading) {
    var poradi = 0;

    function rozdel(uzel) {
      Array.prototype.slice.call(uzel.childNodes).forEach(function (dite) {
        if (dite.nodeType === Node.TEXT_NODE) {
          var kusy = document.createDocumentFragment();
          Array.from(dite.textContent).forEach(function (znak) {
            if (/\s/.test(znak)) {
              kusy.appendChild(document.createTextNode(znak));
              return;
            }
            var s = document.createElement("span");
            s.className = "ltr";
            s.style.setProperty("--ltr-i", poradi++);
            s.textContent = znak;
            kusy.appendChild(s);
          });
          uzel.replaceChild(kusy, dite);
        } else if (dite.nodeType === Node.ELEMENT_NODE) {
          rozdel(dite); // zachová <em> se žlutou barvou
        }
      });
    }

    heading.setAttribute("aria-label", heading.textContent.replace(/\s+/g, " ").trim());
    heading.classList.add("ltr-heading");
    heading.querySelectorAll(".mask-line").forEach(function (line) {
      line.setAttribute("aria-hidden", "true");
      var vnitrni = line.firstElementChild;
      if (vnitrni) { rozdel(vnitrni); }
    });
  });

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

  /* Délku tahu křivek už nepočítáme: cesty mají v HTML pathLength="1",
     takže si animaci řídí samo CSS pevnými čísly. Nezávisí tedy na
     JavaScriptu a chová se stejně ve všech prohlížečích. */

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

  // Náhled na GitHub Pages: statický hosting bez PHP, ukázková data,
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
        '<span class="schedule-time">' + t.den + " " + t.datum + " · " + t.cas_od + " do " + t.cas_do + "</span>" +
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
            note.textContent = "Náhled webu, termíny jsou ukázkové a rezervace se neukládají.";
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
      t.den + " " + t.datum + " · " + t.cas_od + " do " + t.cas_do + " · " + t.misto;
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
          "Toto je náhled webu, rezervace se zatím neukládají. Na ostrém webu by teď bylo místo rezervované.";
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

  /* ---------- Dekorativní videa a omezený pohyb ----------
     Videa bez ovládání běží sama jako vizuální prvek. Uživatelům, kteří
     mají v systému nastavené omezení animací, je zastavíme a necháme
     jen statický náhled. */
  if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    document.querySelectorAll("video[autoplay]").forEach(function (video) {
      video.autoplay = false;
      video.removeAttribute("autoplay");
      video.pause();
    });
  }

  /* ---------- Tlačítko přehrání u videí s ovládáním ----------
     Videa s vlastním ovládáním ukazují systémové tlačítko prohlížeče.
     Přidáme přes ně stejné kolečko jako u karet s příběhy. Po prvním
     spuštění zmizí a dál se video ovládá běžnými prvky přehrávače.
     Automaticky přehrávaná videa bez ovládání (hero) se netýkají. */
  document
    .querySelectorAll(".video-figure video[controls], .band-video video[controls]")
    .forEach(function (video) {
      var obal = video.parentElement;
      if (!obal) { return; }

      var tlacitko = document.createElement("button");
      tlacitko.type = "button";
      tlacitko.className = "play-badge";
      tlacitko.setAttribute("aria-label", "Přehrát video");
      obal.appendChild(tlacitko);

      tlacitko.addEventListener("click", function () {
        video.play().catch(function () {});
      });

      // schovej po prvním spuštění, ať už jde odkud chce
      video.addEventListener("play", function () {
        tlacitko.classList.add("is-hidden");
      }, { once: true });
    });

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

  /* ---------- Reference (slider) ---------- */
  var refs = document.getElementById("refs");

  if (refs) {
    var slides = refs.querySelectorAll(".ref-slide");
    var counter = refs.querySelector(".refs-counter");
    var progress = refs.querySelector(".refs-progress > span");
    var aktualni = 0;
    var INTERVAL = 7000;
    var casovac = null;
    var klidovyRezim = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    var ukaz = function (index) {
      aktualni = (index + slides.length) % slides.length;
      slides.forEach(function (s, i) {
        s.classList.toggle("is-active", i === aktualni);
      });
      counter.textContent =
        ("0" + (aktualni + 1)).slice(-2) + " / " + ("0" + slides.length).slice(-2);
      restartujProgress();
    };

    var restartujProgress = function () {
      if (!progress || klidovyRezim) { return; }
      progress.style.animation = "none";
      void progress.offsetWidth; // vynutí nové spuštění animace
      progress.style.animation = "";
    };

    var naplanuj = function () {
      if (klidovyRezim) { return; }
      clearInterval(casovac);
      refs.classList.add("is-auto");
      casovac = setInterval(function () { ukaz(aktualni + 1); }, INTERVAL);
    };

    var rucne = function (index) {
      ukaz(index);
      naplanuj(); // po ručním zásahu odpočítávej znovu od začátku
    };

    refs.querySelector("[data-refs-prev]").addEventListener("click", function () { rucne(aktualni - 1); });
    refs.querySelector("[data-refs-next]").addEventListener("click", function () { rucne(aktualni + 1); });

    // šipky na klávesnici, když je slider vidět v okně
    document.addEventListener("keydown", function (e) {
      if (e.key !== "ArrowLeft" && e.key !== "ArrowRight") { return; }
      var r = refs.getBoundingClientRect();
      if (r.bottom < 0 || r.top > window.innerHeight) { return; }
      rucne(e.key === "ArrowRight" ? aktualni + 1 : aktualni - 1);
    });

    // tažení prstem / myší
    var tahOd = null;
    refs.querySelector(".refs-track").addEventListener("pointerdown", function (e) {
      tahOd = e.clientX;
    });
    window.addEventListener("pointerup", function (e) {
      if (tahOd === null) { return; }
      var rozdil = e.clientX - tahOd;
      tahOd = null;
      if (Math.abs(rozdil) > 40) { rucne(rozdil < 0 ? aktualni + 1 : aktualni - 1); }
    });

    // pauza, když na slideru stojí myš
    refs.addEventListener("mouseenter", function () {
      clearInterval(casovac);
      refs.classList.remove("is-auto");
    });
    refs.addEventListener("mouseleave", function () {
      naplanuj();
      restartujProgress();
    });

    ukaz(0);
    naplanuj();
  }

  /* ---------- Prohlížeč fotek (galerie) ---------- */
  var lightbox = document.getElementById("lightbox");
  var galerie = document.getElementById("galerie");

  if (lightbox && galerie) {
    var polozky = Array.prototype.slice.call(galerie.querySelectorAll(".gallery-item"));
    var lbImg = lightbox.querySelector(".lightbox-img");
    var lbText = lightbox.querySelector(".lightbox-text");
    var lbPocet = lightbox.querySelector(".lightbox-counter");
    var aktualni = 0;
    var vratitFokus = null;

    function ukaz(index) {
      aktualni = (index + polozky.length) % polozky.length; // dokola
      var img = polozky[aktualni].querySelector("img");
      lbImg.src = img.src;
      lbImg.alt = img.alt;
      lbText.textContent = img.alt;
      lbPocet.textContent = (aktualni + 1) + " / " + polozky.length;
      // restart animace nájezdu
      lbImg.style.animation = "none";
      void lbImg.offsetWidth;
      lbImg.style.animation = "";
    }

    function otevri(index) {
      vratitFokus = polozky[index];
      ukaz(index);
      lightbox.hidden = false;
      document.body.style.overflow = "hidden";
      lightbox.querySelector(".lightbox-close").focus();
    }

    function zavri() {
      lightbox.hidden = true;
      document.body.style.overflow = "";
      if (vratitFokus) { vratitFokus.focus(); }
    }

    polozky.forEach(function (polozka, i) {
      polozka.addEventListener("click", function () { otevri(i); });
    });

    lightbox.querySelectorAll("[data-lb-close]").forEach(function (el) {
      el.addEventListener("click", zavri);
    });
    lightbox.querySelector("[data-lb-prev]").addEventListener("click", function () { ukaz(aktualni - 1); });
    lightbox.querySelector("[data-lb-next]").addEventListener("click", function () { ukaz(aktualni + 1); });

    document.addEventListener("keydown", function (e) {
      if (lightbox.hidden) { return; }
      if (e.key === "Escape") { zavri(); }
      else if (e.key === "ArrowLeft") { ukaz(aktualni - 1); }
      else if (e.key === "ArrowRight") { ukaz(aktualni + 1); }
    });

    // listování prstem
    var dotekX = null;
    lightbox.addEventListener("touchstart", function (e) {
      dotekX = e.changedTouches[0].clientX;
    }, { passive: true });

    lightbox.addEventListener("touchend", function (e) {
      if (dotekX === null) { return; }
      var rozdil = e.changedTouches[0].clientX - dotekX;
      if (Math.abs(rozdil) > 50) { ukaz(aktualni + (rozdil < 0 ? 1 : -1)); }
      dotekX = null;
    }, { passive: true });
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
        "?subject=" + encodeURIComponent("Poptávka z webu :: The Move") +
        "&body=" + encodeURIComponent(body);
    });
  }
})();

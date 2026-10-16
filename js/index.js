// Startseite: Foto-Slider, Anfrage-Schritte, Lightbox, Kundenstimmen.
(function () {
    'use strict';

    /* ---------- Slider auf Basis von scroll-snap ---------- */

    document.querySelectorAll('[data-slider]').forEach((slider) => {
        const track = slider.querySelector('[data-slider-track]');
        const prev = slider.querySelector('[data-slider-prev]');
        const next = slider.querySelector('[data-slider-next]');
        const dotsBox = slider.querySelector('[data-slider-dots]');
        if (!track) {
            return;
        }
        const slides = Array.from(track.children);

        const maxScroll = () => track.scrollWidth - track.clientWidth;
        const slideLeft = (slide) => slide.offsetLeft - track.offsetLeft;

        function currentIndex() {
            let best = 0;
            let bestDistance = Infinity;
            slides.forEach((slide, i) => {
                const distance = Math.abs(slideLeft(slide) - track.scrollLeft);
                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = i;
                }
            });
            return best;
        }

        function goTo(index) {
            const slide = slides[Math.max(0, Math.min(slides.length - 1, index))];
            track.scrollTo({ left: Math.min(slideLeft(slide), maxScroll()), behavior: 'smooth' });
        }

        const dots = slides.map((_, i) => {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.setAttribute('aria-label', `Zu Bild ${i + 1}`);
            dot.addEventListener('click', () => goTo(i));
            dotsBox?.appendChild(dot);
            return dot;
        });

        function sync() {
            const atEnd = track.scrollLeft >= maxScroll() - 2;
            const index = atEnd ? slides.length - 1 : currentIndex();
            // Punkte, deren Bild gar nicht mehr an den linken Rand rücken kann, zählen zum letzten.
            const lastReachable = slides.findIndex((slide) => slideLeft(slide) >= maxScroll() - 2);
            dots.forEach((dot, i) => {
                dot.hidden = lastReachable !== -1 && i > lastReachable;
                dot.classList.toggle('is-active', atEnd ? i === lastReachable : i === index);
            });
            if (prev) {
                prev.disabled = track.scrollLeft <= 2;
            }
            if (next) {
                next.disabled = atEnd;
            }
        }

        prev?.addEventListener('click', () => goTo(currentIndex() - 1));
        next?.addEventListener('click', () => goTo(currentIndex() + 1));
        track.addEventListener('scroll', () => window.requestAnimationFrame(sync), { passive: true });
        window.addEventListener('resize', sync);
        sync();
    });

    /* ---------- Anfrage in vier Schritten ---------- */

    const quiz = document.querySelector('[data-quiz]');
    if (quiz) {
        const panes = Array.from(quiz.querySelectorAll('[data-quiz-pane]'));
        const back = quiz.querySelector('[data-quiz-back]');
        const next = quiz.querySelector('[data-quiz-next]');
        const counter = quiz.querySelector('[data-quiz-current]');
        const progress = quiz.querySelector('[data-quiz-progress]');
        const summary = quiz.querySelector('[data-quiz-summary]');
        const send = quiz.querySelector('[data-quiz-send]');
        const note = quiz.querySelector('#quizNote');
        const last = panes.length - 1;
        let step = 0;

        const answerOf = (pane) => pane.querySelector('input[type="radio"]:checked')?.value || '';

        function answers() {
            return panes.slice(0, last).map((pane) => [pane.dataset.quizPane, answerOf(pane)]);
        }

        function buildSummary() {
            summary.replaceChildren();
            answers().forEach(([label, value]) => {
                const row = document.createElement('div');
                const dt = document.createElement('dt');
                const dd = document.createElement('dd');
                dt.textContent = label;
                dd.textContent = value;
                row.append(dt, dd);
                summary.appendChild(row);
            });
        }

        function buildLink() {
            const lines = ['Hallo, ich möchte eine Solarmontage anfragen.'];
            answers().forEach(([label, value]) => lines.push(`${label}: ${value}`));
            const extra = note.value.trim();
            if (extra !== '') {
                lines.push(`Anmerkung: ${extra}`);
            }
            send.href = `${quiz.dataset.whatsapp}?text=${encodeURIComponent(lines.join('\n'))}`;
        }

        function render() {
            panes.forEach((pane, i) => pane.classList.toggle('is-active', i === step));
            counter.textContent = String(step + 1);
            progress.style.width = `${((step + 1) / panes.length) * 100}%`;
            back.hidden = step === 0;
            next.hidden = step === last;
            next.disabled = step < last && answerOf(panes[step]) === '';
            if (step === last) {
                buildSummary();
                buildLink();
            }
        }

        quiz.addEventListener('change', (e) => {
            if (e.target.matches('input[type="radio"]')) {
                next.disabled = false;
            }
        });
        note.addEventListener('input', buildLink);
        next.addEventListener('click', () => {
            if (step < last && answerOf(panes[step]) !== '') {
                step += 1;
                render();
            }
        });
        back.addEventListener('click', () => {
            step = Math.max(0, step - 1);
            render();
        });
        quiz.addEventListener('submit', (e) => e.preventDefault());
        render();
    }

    /* ---------- Lightbox der Projektfotos ---------- */

    const lightbox = document.getElementById('lightbox');
    const thumbs = Array.from(document.querySelectorAll('[data-lightbox]'));
    if (lightbox && thumbs.length > 0 && typeof lightbox.showModal === 'function') {
        const image = lightbox.querySelector('img');
        const caption = lightbox.querySelector('figcaption');
        let current = 0;

        function show(index) {
            current = (index + thumbs.length) % thumbs.length;
            const thumb = thumbs[current];
            image.src = thumb.dataset.src;
            image.alt = thumb.dataset.caption;
            caption.textContent = thumb.dataset.caption;
        }

        thumbs.forEach((thumb, i) => {
            thumb.addEventListener('click', () => {
                show(i);
                lightbox.showModal();
            });
        });
        lightbox.querySelectorAll('[data-lightbox-step]').forEach((btn) => {
            btn.addEventListener('click', () => show(current + Number(btn.dataset.lightboxStep)));
        });
        lightbox.querySelector('[data-lightbox-close]').addEventListener('click', () => lightbox.close());
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) {
                lightbox.close();
            }
        });
        lightbox.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') {
                show(current - 1);
            } else if (e.key === 'ArrowRight') {
                show(current + 1);
            }
        });
    }

    /* ---------- Kundenstimmen: Filter, Nachladen, Admin-Antwort ---------- */

    const state = window.JD_REVIEWS;
    const list = document.getElementById('bewertungen-liste');
    const more = document.getElementById('mehrBewertungen');
    const empty = document.getElementById('bewertungenLeer');
    const filter = document.getElementById('ratingFilter');

    function loadReviews(replace) {
        list.classList.add('is-loading');
        more.classList.add('loading');

        fetch(`index.php?offset=${state.offset}&rating_filter=${state.ratingFilter}&ajax=1`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Netzwerkfehler');
                }
                return response.text();
            })
            .then((html) => {
                const doc = new DOMParser().parseFromString(html, 'text/html');
                const items = doc.querySelectorAll('.review-item');
                if (replace) {
                    list.replaceChildren();
                }
                items.forEach((item) => list.appendChild(item));
                more.hidden = doc.querySelector('.load-more') === null;
                empty.hidden = list.children.length > 0;
            })
            .catch((error) => console.error('Fehler beim Laden:', error))
            .finally(() => {
                list.classList.remove('is-loading');
                more.classList.remove('loading');
            });
    }

    if (state && list && more && filter) {
        filter.addEventListener('change', () => {
            state.ratingFilter = Number(filter.value);
            state.offset = 0;
            loadReviews(true);
        });
        more.addEventListener('click', () => {
            state.offset += state.limit;
            loadReviews(false);
        });

        // Antwortformulare kommen auch per Nachladen – deshalb an der Liste lauschen.
        list.addEventListener('submit', (e) => {
            const form = e.target.closest('.reply-form');
            if (!form) {
                return;
            }
            e.preventDefault();
            const data = new FormData(form);
            fetch('index.php', { method: 'POST', body: data })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Netzwerkfehler');
                    }
                    const reply = document.createElement('div');
                    reply.className = 'reply';
                    const title = document.createElement('strong');
                    title.textContent = 'Antwort von Jon Dienstleistungen';
                    const text = document.createElement('p');
                    text.textContent = String(data.get('reply_text'));
                    reply.append(title, text);
                    form.parentElement.replaceWith(reply);
                })
                .catch((error) => console.error('Fehler beim Senden der Antwort:', error));
        });
    }
})();

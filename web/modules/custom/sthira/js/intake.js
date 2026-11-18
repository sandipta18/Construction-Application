/**
 * @file
 * Conversational request box ("AI assist", demo mode).
 *
 * Parses a plain-language request in the browser with rules matched against
 * the material catalog and the user's projects, then fills the form. No
 * external AI service is called.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
  const DAYS = { sun: 0, sunday: 0, mon: 1, monday: 1, tue: 2, tues: 2, tuesday: 2, wed: 3, wednesday: 3, thu: 4, thur: 4, thurs: 4, thursday: 4, fri: 5, friday: 5, sat: 6, saturday: 6 };
  const UNIT_RE = '(bags?|tonnes?|tons?|mt|t|nos|numbers?|pcs|pieces?|units?|coils?|rolls?|cu\\.?\\s?m|cum|cubic\\s*met(?:er|re)s?|sq\\.?\\s?ft|sqft|square\\s*feet|buckets?|cans?|blocks?|bricks?|lengths?)';

  function esc(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function iso(d) {
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function pretty(d) {
    return d.toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short' });
  }

  function inr(n) {
    return '₹' + Math.round(n).toLocaleString('en-IN');
  }

  function short(n) {
    if (n >= 1e7) {
      return '₹' + (n / 1e7).toFixed(2).replace(/\.?0+$/, '') + ' crore';
    }
    if (n >= 1e5) {
      return '₹' + (n / 1e5).toFixed(1).replace(/\.0$/, '') + ' lakh';
    }
    return inr(n);
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function findItem(t, items) {
    let best = null;
    let bestScore = 0;
    items.forEach((it) => {
      let s = 0;
      it.keywords.forEach((k) => {
        if (new RegExp('\\b' + esc(k.toLowerCase()) + '\\b').test(t)) {
          s += k.length;
        }
      });
      if (s > bestScore) {
        bestScore = s;
        best = it;
      }
    });
    return best;
  }

  function findProject(t, projects) {
    let best = null;
    let bestScore = 0;
    projects.forEach((p) => {
      let s = 0;
      p.keywords.forEach((k) => {
        if (new RegExp('\\b' + esc(k) + '\\b').test(t)) {
          s += k.length;
        }
      });
      if (s > bestScore) {
        bestScore = s;
        best = p;
      }
    });
    if (!best && projects.length === 1) {
      return { project: projects[0], assumed: true };
    }
    return best ? { project: best, assumed: false } : null;
  }

  function findQty(t) {
    const re = new RegExp('(\\d+(?:[.,]\\d+)?)\\s*(k\\b|thousand|lakh)?\\s*' + UNIT_RE + '?', 'gi');
    let m;
    let fallback = null;
    while ((m = re.exec(t)) !== null) {
      const after = t.slice(re.lastIndex, re.lastIndex + 12);
      const before = t.slice(Math.max(0, m.index - 6), m.index);
      const isDate = /^\s*(st|nd|rd|th)?\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)/.test(after) ||
        /^\s*(days?|weeks?|months?|hrs?|hours?|am|pm)\b/.test(after) ||
        /^[/-]\d/.test(after) ||
        /(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s*$/.test(before);
      if (isDate || m[1] === '' || m[0].trim() === '') {
        continue;
      }
      let n = parseFloat(m[1].replace(',', '.'));
      if (m[2]) {
        n *= m[2].toLowerCase() === 'lakh' ? 100000 : 1000;
      }
      if (m[3]) {
        return n;
      }
      if (fallback === null) {
        fallback = n;
      }
    }
    return fallback;
  }

  function findDate(t, todayIso) {
    const today = new Date(todayIso + 'T00:00:00');
    const add = (n) => {
      const d = new Date(today);
      d.setDate(d.getDate() + n);
      return d;
    };
    let m;
    if (/\bday after tomorrow\b/.test(t)) {
      return { date: add(2), phrase: 'day after tomorrow' };
    }
    if (/\b(tomorrow|tmrw|tmr)\b/.test(t)) {
      return { date: add(1), phrase: 'tomorrow' };
    }
    if (/\b(today|tonight|asap|immediately|right away)\b/.test(t)) {
      return { date: add(1), phrase: 'as soon as possible' };
    }
    if ((m = t.match(/\bin\s+(\d+)\s+days?\b/))) {
      return { date: add(parseInt(m[1], 10)), phrase: 'in ' + m[1] + ' days' };
    }
    if ((m = t.match(/\bin\s+(\d+)\s+weeks?\b/))) {
      return { date: add(7 * parseInt(m[1], 10)), phrase: 'in ' + m[1] + ' weeks' };
    }
    if (/\bnext week\b/.test(t)) {
      return { date: add(7), phrase: 'next week' };
    }
    if (/\bend of (the )?month\b/.test(t)) {
      return { date: new Date(today.getFullYear(), today.getMonth() + 1, 0), phrase: 'end of month' };
    }
    if ((m = t.match(/\b(\d{1,2})(?:st|nd|rd|th)?\s*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\b/)) ||
        (m = t.match(/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s*(\d{1,2})(?:st|nd|rd|th)?\b/))) {
      const day = parseInt(/\d/.test(m[1]) ? m[1] : m[2], 10);
      const mon = MONTHS.indexOf((/\d/.test(m[1]) ? m[2] : m[1]).slice(0, 3));
      let d = new Date(today.getFullYear(), mon, day);
      if (d < today) {
        d = new Date(today.getFullYear() + 1, mon, day);
      }
      return { date: d, phrase: m[0] };
    }
    if ((m = t.match(/\b(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?\b/))) {
      let y = m[3] ? parseInt(m[3], 10) : today.getFullYear();
      if (y < 100) {
        y += 2000;
      }
      const d = new Date(y, parseInt(m[2], 10) - 1, parseInt(m[1], 10));
      return { date: d, phrase: m[0] };
    }
    const dayRe = /\b(next\s+)?(monday|mon|tuesday|tues|tue|wednesday|wed|thursday|thurs|thur|thu|friday|fri|saturday|sat|sunday|sun)\b/;
    if ((m = t.match(dayRe))) {
      const target = DAYS[m[2]];
      let diff = (target - today.getDay() + 7) % 7;
      if (diff === 0) {
        diff = 7;
      }
      if (m[1]) {
        diff += diff < 7 ? 7 : 0;
      }
      return { date: add(diff), phrase: m[0] };
    }
    return { date: add(7), phrase: null };
  }

  function parse(text, s) {
    const t = ' ' + text.toLowerCase().replace(/\s+/g, ' ') + ' ';
    const item = findItem(t, s.items);
    const proj = findProject(t, s.projects);
    const qty = findQty(t);
    let date = findDate(t, s.today);
    const urgent = /\b(urgent|urgently|asap|emergency|immediately)\b/.test(t);
    if (urgent && !date.phrase) {
      const d = new Date(s.today + 'T00:00:00');
      d.setDate(d.getDate() + 2);
      date = { date: d, phrase: 'urgent' };
    }
    return { item, proj, qty, date, urgent };
  }

  function setValue(el, value) {
    if (!el) {
      return;
    }
    el.value = value;
    el.dispatchEvent(new Event('change', { bubbles: true }));
    const wrap = el.closest('.form-item');
    if (wrap) {
      wrap.classList.remove('is-filled');
      void wrap.offsetWidth;
      wrap.classList.add('is-filled');
    }
  }

  function routeText(s, project, amount) {
    const pm = project ? project.pm : 'the PM';
    return 'Next: suppliers bid, Procurement picks the best, then ' + escapeHtml(s.director) + ' approves the order';
  }

  function render(result, s) {
    const { item, proj, qty, date, urgent } = result;
    const project = proj ? proj.project : null;
    const chip = (label, value, ok) => '<span class="found' + (ok ? '' : ' found--missing') + '"><small>' + label + '</small>' + (ok ? escapeHtml(value) : 'please choose') + '</span>';
    let html = '<div class="found-row">';
    html += chip('Material', item ? item.short : '', !!item);
    html += chip('Quantity', qty && item ? qty.toLocaleString('en-IN') + ' ' + item.unit : (qty || ''), !!qty);
    html += chip('Project', project ? project.short : '', !!project);
    html += chip('Needed by', pretty(date.date), true);
    if (urgent) {
      html += chip('Priority', 'Urgent', true);
    }
    html += '</div>';
    if (item && qty) {
      const amount = qty * item.rate;
      const bits = [];
      if (project && project.stockDays[item.id] !== undefined && project.stockDays[item.id] < 5) {
        bits.push('<span class="t-bad">Only ' + project.stockDays[item.id] + ' days of stock left</span>');
      }
      if (project && s.finance && project.remaining[item.category] !== undefined && project.remaining[item.category] < amount) {
        bits.push('<span class="t-bad">Exceeds remaining ' + escapeHtml(item.category.toLowerCase()) + ' budget</span>');
      }
      if (bits.length) {
        html += '<p class="found-note">' + bits.join(' · ') + '</p>';
      }
    }
    return html;
  }

  Drupal.behaviors.sthiraIntake = {
    attach(context) {
      const s = drupalSettings.sthiraIntake;
      if (!s) {
        return;
      }
      once('sthira-intake', '[data-intake-box]', context).forEach((box) => {
        const form = box.closest('form');
        const text = box.querySelector('[data-intake-text]');
        const go = box.querySelector('[data-intake-go]');
        const thinking = box.querySelector('[data-intake-thinking]');
        const step = box.querySelector('[data-intake-step]');
        const out = box.querySelector('[data-intake-result]');
        const field = (k) => form.querySelector('[data-intake="' + k + '"]');
        const unitEl = form.querySelector('[data-intake-unit]');
        const status = form.querySelector('[data-intake-status]');
        const routeEl = form.querySelector('[data-intake-route]');

        const updateUnit = () => {
          const id = parseInt(field('item').value, 10);
          const it = s.items.find((i) => i.id === id);
          if (unitEl) {
            unitEl.textContent = it ? it.unit : '';
          }
          const qty = parseFloat(field('qty').value);
          const pid = parseInt(field('project').value, 10);
          const project = s.projects.find((p) => p.id === pid);
          if (routeEl) {
            if (it && qty > 0) {
              routeEl.hidden = false;
              routeEl.innerHTML = 'Estimate <b>' + short(qty * it.rate) + '</b> · ' + routeText(s, project, qty * it.rate);
            }
            else {
              routeEl.hidden = true;
            }
          }
        };
        ['item', 'qty', 'project'].forEach((k) => {
          const el = field(k);
          if (el) {
            el.addEventListener('change', updateUnit);
            el.addEventListener('input', updateUnit);
          }
        });
        updateUnit();

        const run = () => {
          const value = text.value.trim();
          if (!value) {
            text.focus();
            return;
          }
          out.hidden = true;
          thinking.hidden = false;
          go.disabled = true;
          const steps = ['Reading your request…', 'Matching material and project…', 'Checking stock and budget…'];
          let i = 0;
          step.textContent = steps[0];
          const timer = setInterval(() => {
            i += 1;
            if (i < steps.length) {
              step.textContent = steps[i];
            }
          }, 380);
          setTimeout(() => {
            clearInterval(timer);
            const result = parse(value, s);
            thinking.hidden = true;
            go.disabled = false;
            out.innerHTML = render(result, s);
            out.hidden = false;
            if (result.item) {
              setValue(field('item'), result.item.id);
            }
            if (result.qty) {
              setValue(field('qty'), result.qty);
            }
            if (result.proj) {
              setValue(field('project'), result.proj.project.id);
            }
            setValue(field('date'), iso(result.date.date));
            setValue(field('priority'), result.urgent ? 'urgent' : 'normal');
            field('raw').value = value;
            if (status) {
              status.textContent = 'Filled in for you. Check and submit.';
              status.classList.add('is-ai');
            }
            updateUnit();
          }, 1200);
        };

        go.addEventListener('click', run);
        text.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            run();
          }
        });
        box.querySelectorAll('[data-intake-sample]').forEach((b) => {
          b.addEventListener('click', () => {
            text.value = '';
            const sample = b.getAttribute('data-intake-sample');
            let n = 0;
            const typer = setInterval(() => {
              n += 2;
              text.value = sample.slice(0, n);
              if (n >= sample.length) {
                clearInterval(typer);
                run();
              }
            }, 18);
          });
        });
      });
    },
  };
})(Drupal, drupalSettings, once);

/* ITK CMS counters. The deferred loader exposes the API before any counter starts. */
(function () {
  'use strict'

  if (window.itkAnalytics && typeof window.itkAnalytics.start === 'function') return

  var script = document.currentScript || document.querySelector('script[data-itk-analytics]')
  function attribute(name) { return script ? script.getAttribute(name) || '' : '' }

  var config = {
    metrika: attribute('data-metrika'),
    ga: attribute('data-ga'),
  }
  var webvisor = attribute('data-webvisor') === '1'
  var state = Object.create(null)
  var api = window.itkAnalytics = { start: start, started: [] }

  function load(src) {
    var counter = document.createElement('script')
    counter.async = true
    counter.src = src
    document.head.appendChild(counter)
  }

  function metrika() {
    window.ym = window.ym || function () {
      (window.ym.a = window.ym.a || []).push(arguments)
    }
    window.ym.l = +new Date()
    var options = { clickmap: true, trackLinks: true, accurateTrackBounce: true }
    if (webvisor) options.webvisor = true
    window.ym(config.metrika, 'init', options)
    var src = 'https://mc.yandex.ru/metrika/tag.js?id=' + encodeURIComponent(config.metrika)
    for (var i = 0; i < document.scripts.length; i++) {
      if (document.scripts[i].src === src) return
    }
    load(src)
  }

  function ga() {
    window.dataLayer = window.dataLayer || []
    window.gtag = function () { window.dataLayer.push(arguments) }
    window.gtag('js', new Date())
    window.gtag('config', config.ga)
    load('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(config.ga))
  }

  function start(list) {
    if (typeof list === 'undefined') list = ['metrika', 'ga']
    if (!Array.isArray(list)) return

    var counters = []
    for (var i = 0; i < list.length; i++) {
      var key = list[i]
      if ((key !== 'metrika' && key !== 'ga') || !config[key] || state[key]) continue
      // Reserve the whole selection before inserting scripts: overlapping calls cannot claim it again.
      state[key] = true
      counters.push(key)
    }
    for (var j = 0; j < counters.length; j++) {
      api.started.push(counters[j])
      if (counters[j] === 'metrika') metrika()
      else ga()
    }
    if (counters.length) {
      document.dispatchEvent(new CustomEvent('itk-analytics:started', {
        bubbles: true,
        detail: { counters: counters },
      }))
    }
  }

  if (attribute('data-start') === 'auto') api.start()
})()

import { h } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { describe, expect, it } from 'vitest'
import RequestDetails from './RequestDetails.vue'

const field = (k, label, t = 'text') => ({ k, label, t })
async function render(request, fields) {
  const html = await renderToString(h(RequestDetails, { request, form: fields ? { fields } : null }))
  // Scoped CSS attributes and Vue fragment boundaries do not affect the rendered label/value pairs.
  return html.replace(/ data-v-[0-9a-f]+/g, '').replace(/<!--(?:\[|\])?-->/g, '')
}

describe('the public form values in a request drawer', () => {
  it('shows the named visitor and every order line when there was no customer comment', async () => {
    const name = 'Ivan Petrov'
    const items = 'Left brace × 2\nRight brace × 1'
    const html = await render({ id: 2, form: 'order', name, phone: '12345', place: 'Cart', note: '', comment: 'Private staff note', data: { name, phone: '12345', items, place: 'Footer', comment: '' } },
      [field('name', 'Customer name'), field('phone', 'Contact number'), field('comment', 'Customer instructions'), field('items', 'Order contents', 'textarea'), field('place', 'Submitted from')])
    expect(html).toContain('Order contents')
    expect(html).toContain(items)
    expect(html).toContain('Customer instructions')
    expect(html).toContain('Submitted from')
    expect(html).toContain('Footer')
    expect(html.match(/Ivan Petrov/g)).toHaveLength(1)
    expect(html.match(/>12345<\/dd>/g)).toHaveLength(1)
    expect(html).toContain('href="tel:12345"')
    expect(html).not.toContain('Private staff note')
  })

  it('uses custom field labels, escapes literal markup, and omits undeclared keys', async () => {
    const html = await render({ id: 3, place: 'Custom form', note: 'hello', data: { message: 'hello', email: 'a@example.com', instruction: '<b>tag</b>\nsecond line', empty: '   ', hidden: 'not for this form' } },
      [field('message', 'Customer question'), field('email', 'Reply to'), field('instruction', 'Site instructions'), field('empty', 'Empty optional field')])
    expect(html).toContain('Customer question')
    expect(html.match(/hello/g)).toHaveLength(1)
    expect(html).toContain('Reply to')
    expect(html).toContain('Site instructions')
    expect(html).toContain('&lt;b&gt;tag&lt;/b&gt;\nsecond line')
    expect(html).not.toContain('<b>tag</b>')
    expect(html).not.toContain('Empty optional field')
    expect(html).not.toContain('not for this form')
  })

  it('honours hideOnDetail for custom fields, contact actions and the public note', async () => {
    const hidden = (k) => ({ ...field(k, 'Hidden ' + k), detail: false })
    const data = { name: 'Hidden name', phone: '123456', city: 'Hidden city', comment: 'Internal code', email: 'Secret address', message: 'Public answer' }
    const html = await render({ id: 6, ...data, note: data.comment, data },
      [hidden('name'), hidden('phone'), hidden('city'), hidden('comment'), hidden('email'), field('message', 'Customer question')])
    expect(html).not.toMatch(/Hidden|Secret address|123456|tel:|Internal code/)
    expect(html).toContain('Customer question')
    expect(html).toContain('Public answer')
  })

  it('keeps distinct declared message answers with the server alias order and PHP zero handling', async () => {
    const fields = [field('text', 'Delivery address'), field('message', 'Sales question'), field('comment', 'Visitor comment')]
    const request = { id: 7, note: 'Main answer', data: { text: 'Street 9', message: 'Other answer', comment: 'Main answer' } }
    const html = await render(request, fields)
    expect(html).toContain('<dt>Visitor comment</dt><dd>Main answer</dd>')
    expect(html).toContain('<dt>Sales question</dt><dd>Other answer</dd>')
    expect(html).toContain('<dt>Delivery address</dt><dd>Street 9</dd>')
    expect(html.match(/Main answer/g)).toHaveLength(1)
    const zero = await render({ ...request, note: 'Other answer', data: { comment: '0', message: 'Other answer' } }, fields)
    expect(zero).toContain('<dt>Sales question</dt><dd>Other answer</dd>')
    expect(zero).toContain('<dt>Visitor comment</dt><dd>0</dd>')
    const zeroOnly = await render({ id: 8, note: '', data: { comment: '0' } }, [field('comment', 'Visitor comment')])
    expect(zeroOnly.match(/Visitor comment/g)).toHaveLength(1)
    expect(zeroOnly).toContain('<dt>Visitor comment</dt><dd>0</dd>')
  })

  it('displays false, zero and select labels, and supports a legacy request without form metadata', async () => {
    const html = await render({ id: 4, data: { subscribed: false, seats: 0, option: 'opt1' } },
      [field('subscribed', 'Updates', 'toggle'), field('seats', 'Seats'), { ...field('option', 'Variant', 'select'), opts: [['opt1', 'First variant']] }])
    expect(html).toContain('Нет')
    expect(html).toContain('>0</dd>')
    expect(html).toContain('First variant')
    const old = await render({ id: 5, name: 'Legacy', note: 'Historic message', form: 'removed' })
    expect(old).toContain('Legacy')
    expect(old).toContain('Historic message')
  })
})

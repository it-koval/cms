{# The section “@@key@@”. Variables: section (section.data.*), page, is_h1 #}
<section class="@@cssClass@@"{{ itk_section_attrs(section) }}>
    {% if is_h1 %}<h1>{{ itk_field(section, 'headline') }}</h1>{% else %}<h2>{{ itk_field(section, 'headline') }}</h2>{% endif %}
    <div>{{ itk_field(section, 'text') }}</div>
    {% if section.data.image.src|default('') != '' %}<img src="{{ media_url(section.data.image) }}" alt="{{ section.data.image.alt|default('') }}"{{ itk_field_attrs(section, 'image') }}>{% endif %}
</section>

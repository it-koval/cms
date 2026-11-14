{# The page template “@@key@@”. Variables: page (title, url, seo, fields, sections, h1Id), site #}
{% extends ['layout.html.twig', '@ItkCms/layout.html.twig'] %}

{% block content %}
    {{ render_sections(page) }}
{% endblock %}

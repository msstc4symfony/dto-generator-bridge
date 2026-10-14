
- PHP 7.4 builds bundle PCRE2 10.35, which compiles an unknown Unicode property such as `\p{Foo}` (it matches nothing);
  PCRE2 10.44 refuses it, and 10.43+ also accepts variable-length lookbehind. A test that needs a pattern PCRE cannot
  compile on every PHP line uses an unbalanced group (`a(`).

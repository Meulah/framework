# Opia Language RFC

Status: Proposed
Target: Opia language version 1
Framework baseline: PHP 8.1
File extension: .opia

Opia is named after a knife in Benin. The name continues the framework-tool naming tradition represented by Blade and Razor, but the language deliberately does not copy either system.

This RFC specifies a strict, HTML-shaped template language for Meulah. It is a language proposal only. It does not specify or authorize an implementation in this change.

## 1. Design goals

Opia should:

1. look recognizable to someone learning HTML;
2. make escaped output shorter and easier than unsafe output;
3. reject missing data instead of silently hiding mistakes;
4. keep control flow visible in the document tree;
5. permit only a small, deterministic expression language;
6. prevent templates from executing arbitrary PHP;
7. separate application logic, dependency resolution, and environment access from presentation;
8. produce useful errors with exact template source locations;
9. behave consistently on PHP 8.1 and newer supported versions;
10. support static dependency discovery for includes and layouts;
11. permit cacheable parsing and compilation without eval();
12. preserve ordinary HTML, JavaScript, CSS, and JSON source where Opia syntax is not valid;
13. leave room for future components and contextual attributes without silently changing version-one templates.
14. remain dependency-free in version one.

Safety and predictability take priority over terseness and compatibility with existing PHP template languages.

## 2. Explicit non-goals

Version one does not provide:

- arbitrary PHP blocks or expressions;
- eval() or runtime source evaluation;
- service-container access;
- environment-variable, configuration, session, request, or global-variable access;
- static method calls;
- object method calls;
- dynamic function calls;
- assignment or mutation;
- imports, macros, filters, pipes, or user-defined functions inside templates;
- route generation or translation as language primitives;
- automatic object serialization;
- automatic raw HTML;
- template inheritance compatible with Blade, Twig, or another engine;
- JavaScript, CSS, URL, or HTML-attribute interpolation;
- components;
- dynamic attributes;
- asynchronous rendering or streaming;
- a general-purpose programming language;
- a regex-only compiler architecture;
- modification of Meulah\View\View or its current PHP template behavior.

Applications may expose narrow formatting or lookup operations through the registered-function mechanism defined later. That does not make PHP functions or services automatically available.

## 3. File extension and source encoding

Opia templates use the .opia extension.

A source file must be valid UTF-8. A leading UTF-8 byte-order mark may be ignored. Any other invalid UTF-8 produces a compile error.

Line endings are preserved in literal output. Source locations count lines beginning at 1 and Unicode code points within a line beginning at column 1. A tab advances one source column for location reporting; a diagnostic renderer may expand it visually.

An Opia file represents an HTML document or HTML fragment. It is not a generic text, JSON, JavaScript, or CSS template format.

## 4. Template lookup rules

A template is identified by a logical name such as users.card.

The proposed logical-name grammar is:

~~~text
template-name = segment, { ".", segment } ;
segment       = letter, { letter | digit | "_" | "-" } ;
letter        = "A"?"Z" | "a"?"z" ;
digit         = "0"?"9" ;
~~~

Resolution maps dots to directory separators and appends .opia:

~~~text
users.card  ->  <configured-root>/users/card.opia
app         ->  <configured-root>/app.opia
~~~

Rules:

- callers provide the logical name without an extension;
- empty segments, absolute paths, slash characters, backslashes, NUL bytes, and traversal segments are rejected;
- logical names are case-sensitive on every platform, including Windows;
- the resolved canonical file must remain inside the configured template root;
- a symlink that escapes the root is rejected;
- ambiguous case-only files are a deployment error;
- include and layout names are static source literals in version one;
- there is no automatic fallback from .opia to .php or from .php to .opia;
- the existing PHP View renderer and its lookup rules remain independent.

Applications may configure multiple roots later, but ordering, override, and package-namespace semantics are not part of version one.

## 5. Document model and whitespace handling

Opia requires an HTML-aware tokenizer and a structural parser. It must not be implemented by replacing patterns in the source with regular expressions.

The parser recognizes:

- normal HTML start tags, end tags, attributes, comments, doctypes, and text;
- HTML raw-text regions such as script and style;
- escaped-output delimiters in permitted text contexts;
- reserved Opia structural elements;
- expressions inside specific Opia attributes.

Opia does not use a browser DOM as its parser. Browser error recovery can move elements, insert nodes, and silently repair malformed markup. Opia source must be validated before browser parsing.

Version one proposes strict balancing for non-void elements:

- Opia structural elements must always use their documented closing or self-closing form;
- custom elements must have matching closing tags unless explicitly self-contained by a future Opia rule;
- HTML void elements follow the standard HTML void-element set;
- omitted optional HTML closing tags are not accepted by the strict parser;
- a mismatched or missing closing tag is an error rather than a recovery opportunity.

Literal text and whitespace are preserved except for standalone structural-tag trimming.

A structural tag is standalone when its entire source line consists of optional spaces or tabs, exactly one Opia opening, closing, or self-closing structural tag, optional spaces or tabs, and then a line ending or end of file. The indentation, tag, trailing horizontal whitespace, and one following line ending are removed. Content lines are not dedented.

Example:

~~~opia
<when test="user.active">
    Active
</when>
~~~

The structural lines disappear. The four spaces before Active remain literal source text and are normally collapsed by HTML rendering.

Escaped-output delimiter whitespace is syntax, not output:

~~~opia
[[user.name]]
[[ user.name ]]
~~~

Both evaluate the same expression.

No general whitespace minification, pretty printing, or automatic indentation is performed.

## 6. Escaped output semantics

Escaped output uses:

~~~opia
[[ user.name ]]
~~~

Version one recognizes this delimiter only in ordinary HTML text nodes.

The expression must evaluate to one of:

- string;
- integer;
- finite floating-point number.

Boolean, null, list, map, resource, and object results are rejected for direct output. Applications must make formatting choices explicit through an expression or registered function.

Before escaping, the value must be valid UTF-8. Output escaping replaces at least:

~~~text
&  -> &amp;
<  -> &lt;
>  -> &gt;
"  -> &quot;
'  -> &#039;
~~~

Existing character references in data are escaped again. Opia never assumes a string is already HTML-safe.

The escaped result belongs only to an HTML text-node context. It is not a safe URL, attribute value, JavaScript string, CSS token, JSON token, or HTML tag name.

Output syntax is not recognized inside:

- HTML tag names;
- HTML attribute names;
- ordinary HTML attribute values;
- comments;
- doctypes;
- script raw text;
- style raw text;
- Opia attributes that expect static names;
- raw output.

An occurrence of [[ in an ordinary HTML attribute value is proposed to be a compile error because it is likely an unsafe interpolation attempt. In script and style raw-text regions it remains literal source.

## 7. Raw HTML security model

Raw output uses a self-closing element:

~~~opia
<raw value="trustedHtml" />
~~~

The value attribute contains an Opia expression.

The expression must evaluate to an engine-recognized trusted-HTML capability. A plain string is never accepted, regardless of its variable name, source, or previous escaping.

The trusted capability may be created only by:

- trusted application code outside a template; or
- an explicitly registered sanitizer or renderer function that returns the capability.

The final PHP type name and construction API are unresolved, but the capability requirement is normative.

Raw HTML rules:

- insertion is allowed only where an HTML fragment is valid;
- it cannot construct or replace an attribute, tag name, script body, style body, or template expression;
- inserted bytes are not reparsed as Opia, preventing second-pass template injection;
- null and plain strings produce a render error;
- the capability should be immutable;
- trusted status is contextual to HTML fragments and does not imply safety for JavaScript, CSS, URLs, headers, or JSON;
- errors must not print the raw value.

Templates are trusted application source, but template data is treated as untrusted. The trusted capability is an explicit boundary for application-reviewed or sanitized HTML.

## 8. Built-in structural elements

Version one reserves these bare element names:

- raw
- when
- elsewhen
- otherwise
- each
- none
- page
- slot
- yield
- include

All names are written in lowercase. A case variant such as When or EACH is rejected rather than treated as an ordinary element.

Every structural attribute value must be quoted. Duplicate attributes and attributes not listed below are errors, except for explicit include arguments.

| Element | Required form | Attributes | Permitted content or context |
| --- | --- | --- | --- |
| raw | self-closing | value: expression | no children; ordinary fragment position |
| when | paired | test: expression | primary body, then direct elsewhen branches, then optional otherwise |
| elsewhen | paired | test: expression | direct child branch of when |
| otherwise | paired | none | final direct child branch of when |
| each | paired | items: expression; as: identifier; key: optional identifier | loop body followed by optional direct none |
| none | paired | none | final direct child of each |
| page | paired | layout: static template name | direct slots plus default body |
| slot | paired | name: identifier | direct child of page |
| yield | self-closing | name: optional identifier; default: optional literal text | layout content |
| include | self-closing | view: static template name; every other attribute: argument expression | ordinary fragment position |

Structural expression attributes contain expression source directly. They do not use [[ and ]].

### 8.1 Conditions

~~~opia
<when test="user.active">
    Active

    <elsewhen test="user.pending">
        Pending
    </elsewhen>

    <otherwise>
        Inactive
    </otherwise>
</when>
~~~

The test attributes are expressions and must evaluate to boolean exactly. There is no truthiness conversion.

The body before the first elsewhen or otherwise is the primary branch. Elsewhen and otherwise are branch markers and direct children of their owning when.

### 8.2 Loops

~~~opia
<each items="users" as="user" key="index">
    [[ user.name ]]

    <none>
        No users found.
    </none>
</each>
~~~

The items attribute is an expression. The as attribute is a new local variable name. The optional key attribute is a new local variable name containing the iterable's original key. Despite the example name index, it is not an automatically renumbered counter.

The optional none element renders only when iteration produces zero entries.

### 8.3 Layout declarations

~~~opia
<page layout="app">
    <slot name="title">Users</slot>

    Page body
</page>
~~~

The layout attribute is a static template logical name. A page declaration applies one layout to the current template.

### 8.4 Layout yields

~~~opia
<yield name="title" default="Meulah" />
<yield />
~~~

A named yield inserts a named slot. A yield without name inserts the page's default body slot.

### 8.5 Includes

~~~opia
<include view="users.card" user="user" />
~~~

The view attribute is a static template logical name. Every other attribute names an included local variable and contains an expression evaluated in the caller.

### 8.6 Future components

The opia: element namespace is reserved:

~~~opia
<opia:button variant="primary">
    Save
</opia:button>
~~~

All opia: elements are rejected in version one with a reserved-future-syntax diagnostic. They are not passed through as ordinary custom elements.

## 9. Valid and invalid nesting

Structural nesting is part of the grammar, not a runtime convention.

Rules:

- elsewhen may appear only as a direct child branch of when;
- zero or more elsewhen branches may occur after the primary branch;
- otherwise may occur at most once and must be the final branch;
- otherwise may appear only as a direct child of when;
- none may occur at most once, as the final structural child of each;
- slot may occur only as a direct child of page;
- slot names must be unique within a page;
- page must be the only non-whitespace top-level node in its template;
- page cannot appear in an included template;
- version one permits only one layout hop; a layout template cannot itself declare page;
- yield is a layout-placeholder element and cannot occur inside page, slot, when branch markers, or each metadata;
- include may appear in ordinary content, branches, loop bodies, slots, and layouts;
- raw may appear anywhere ordinary HTML fragment content is allowed;
- structural elements may be nested in branch, loop, slot, layout, and included content when the above ownership rules remain valid;
- include and layout cycles are rejected with the complete dependency chain.

Ordinary HTML cannot cross a structural boundary. For example, opening a div in one when branch and closing it after the when is rejected.



## 10. Expression grammar

The proposed version-one grammar is:

~~~text
expression       = coalesce ;

coalesce         = logical-or, [ "??", coalesce ] ;

logical-or       = logical-and, { "or", logical-and } ;
logical-and      = equality, { "and", equality } ;

equality         = comparison, [ ( "==" | "!=" ), comparison ] ;

comparison       = additive,
                   [ ( "<" | "<=" | ">" | ">=" | "in" ), additive ] ;

additive         = multiplicative, { ( "+" | "-" ), multiplicative } ;

multiplicative   = unary, { ( "*" | "/" | "%" ), unary } ;

unary            = ( "not" | "-" ), unary
                 | postfix ;

postfix          = primary,
                   { ".", identifier
                   | "[", expression, "]"
                   } ;

primary          = function-call
                 | identifier
                 | literal
                 | list-literal
                 | "(", expression, ")" ;

function-call    = identifier, "(", [ arguments ], ")" ;
arguments        = expression, { ",", expression }, [ "," ] ;

list-literal     = "[", [ arguments ], "]" ;

literal          = string
                 | integer
                 | decimal
                 | "true"
                 | "false"
                 | "null" ;

identifier       = identifier-start, { identifier-part } ;
identifier-start = letter | "_" ;
identifier-part  = identifier-start | digit ;
~~~

Keywords are lowercase and cannot be used as identifiers:

- true
- false
- null
- and
- or
- not
- in

There is no assignment, increment, decrement, ternary expression, pipe, filter, lambda, spread, named argument, class reference, namespace separator, or semicolon.

## 11. Supported literals

### 11.1 Strings

Single-quoted and double-quoted strings are supported.

Proposed escapes:

- backslash;
- the matching quote;
- newline;
- carriage return;
- tab;
- Unicode scalar escape in the form backslash-u followed by braces and one to six hexadecimal digits.

Unknown escapes, invalid Unicode scalars, and unclosed strings are compile errors. Strings do not interpolate variables.

HTML character references in an Opia expression attribute are decoded by the HTML-aware tokenizer before expression parsing. Authors should normally use the quote type different from the surrounding HTML attribute quote.

### 11.2 Integers

Integers use base-ten ASCII digits. Leading zeroes are rejected except for the literal 0. Values outside the PHP 8.1 integer range are compile errors so behavior does not vary by platform.

### 11.3 Decimals

Decimals require digits on both sides of the decimal point. Scientific notation, NaN, and infinity are not supported in version one. A parsed result must be finite.

### 11.4 Booleans and null

The only boolean literals are true and false. The only null literal is null. They are values, not output strings.

### 11.5 Lists

List literals are ordered and zero-indexed. A trailing comma is permitted. Map or object literals are not supported in version one.

## 12. Property and index access

Property access uses a dot:

~~~opia
[[ user.name ]]
~~~

Index access uses brackets:

~~~opia
[[ users[0].name ]]
[[ labels[currentLocale] ]]
~~~

Resolution is strict:

- on arrays, dot access checks an exact string key;
- on objects, dot access may read only an initialized, declared, public property;
- private, protected, static, dynamic, and uninitialized properties are rejected;
- magic property access through __get or __isset is not invoked;
- getters are not inferred;
- object methods are never invoked;
- array index access accepts an integer or string key;
- ArrayAccess objects are not invoked in version one;
- string character indexing is not supported;
- a missing key or property is an undefined-value error;
- accessing a property or index on null is an error;
- numeric array keys require bracket syntax.

A later data-access contract may replace or extend public-property access, but it must not silently introduce method execution.

## 13. Operators and precedence

From highest to lowest precedence:

1. grouping, function call, property access, and index access;
2. unary not and numeric negation;
3. multiplication, division, and remainder;
4. addition and subtraction;
5. ordering comparisons and in;
6. equality and inequality;
7. and;
8. or;
9. null coalescing.

Null coalescing is right-associative. Other binary operators are left-associative.

Typing rules:

- and, or, and not require booleans;
- arithmetic requires integers or finite decimals and never parses numeric strings;
- plus is numeric addition only, not string concatenation;
- integer overflow, division by zero, and invalid remainder operations are errors;
- ordering compares two numbers or two strings;
- equality compares null, booleans, strings, or numbers without string coercion;
- integers and decimals may compare numerically to each other;
- object and list equality is not supported in version one;
- in requires a scalar left operand and an array or list right operand, using the same strict scalar equality;
- coalescing selects the right operand only when the left operand is null;
- an undefined left operand is still an error and is not rescued by ??.

## 14. Registered function calls

A template may call only a function name explicitly registered with the Opia environment:

~~~opia
[[ formatDate(user.createdAt) ]]
[[ count(users) ]]
~~~

Rules:

- there is no automatic access to PHP functions;
- function names use the identifier grammar and are case-sensitive;
- only direct identifier calls are valid;
- property calls such as user.format() are object method calls and are rejected;
- static calls such as User::find() are rejected;
- dynamic calls such as functions[name]() are rejected;
- named arguments are not supported;
- the registry is immutable during one render;
- template code cannot inspect or modify the registry;
- functions receive only evaluated arguments;
- the container, request, session, environment, and renderer are not implicit arguments;
- registered functions are trusted application code;
- a function exception is wrapped with template call-site context while retaining the original exception as its cause;
- an unregistered call is an error;
- a function result follows the same output, iteration, property, and raw-capability rules as any other value.

The version-one built-in function list remains subject to approval. No PHP function is implicitly built in.

## 15. Undefined variable behavior

Undefined values are always errors.

Undefined includes:

- a root variable absent from render data;
- a missing array key;
- a missing or inaccessible object property;
- an absent loop variable outside its scope;
- an included variable that was not passed;
- an unknown function.

Undefined values do not become null, false, an empty string, or an empty list. Defaults do not replace undefined values.

Applications must provide optional data explicitly as null or use a registered function whose arguments can be evaluated without referencing a missing value.

An error identifies the variable path but never prints the runtime value.

## 16. Null behavior

Null is explicit.

- null may be compared with == and !=;
- null may be handled with ??;
- null may be passed to a registered function;
- null cannot be output directly;
- null is not false;
- null is not iterable;
- property or index access on null is an error;
- a when test evaluating to null is an error;
- raw rejects null.

Example:

~~~opia
[[ user.displayName ?? "Anonymous" ]]
~~~

This works only when displayName exists and its value is null. A missing displayName remains an error.

## 17. Iterable behavior

Each accepts:

- PHP arrays;
- trusted application values implementing Traversable.

Strings, null, booleans, numbers, plain objects, resources, and ArrayAccess-only objects are not iterable.

Iteration rules:

- source iteration order is preserved;
- array integer and string keys are preserved;
- key receives the original key;
- as and key names must be distinct;
- loop bindings are lexical and disappear after each;
- version one proposes rejecting loop variable names that shadow an existing visible binding;
- mutations to source data are not supported by the language;
- an exception thrown by Traversable iteration becomes a render error at the items expression;
- a generator is consumed once;
- none renders only when no item was produced;
- none does not receive loop variables;
- there is no implicit loop metadata object or counter;
- nested loops are valid when their binding names do not collide.

A maximum iteration budget is recommended for defense against resource exhaustion, but its exact configuration is unresolved.



## 18. Layout, slot, and yield semantics

A page template declares one static layout:

~~~opia
<page layout="app">
    <slot name="title">Users</slot>

    <h1>Users</h1>
</page>
~~~

The page's direct slot children define named slots. All remaining page content forms the default unnamed slot. Slot declarations do not appear at their source position in the default slot.

A layout consumes them:

~~~opia
<!doctype html>
<html>
    <head>
        <title><yield name="title" default="Meulah" /></title>
    </head>
    <body>
        <yield />
    </body>
</html>
~~~

Proposed semantics:

- slot names use the identifier grammar;
- named slots are optional unless application conventions say otherwise;
- duplicate slot names are compile errors;
- a supplied empty slot is different from an absent slot;
- default is literal plain text and is HTML-escaped;
- default is used only when the named slot is absent;
- a missing named slot without default yields an empty internal fragment;
- a missing default slot yields an empty internal fragment;
- slot bodies render in the page's lexical root scope;
- the layout also sees the original root render data;
- slot output is rendered once into an internal safe fragment and reused if yielded more than once;
- internal fragments are not public raw-HTML capabilities;
- a page and its layout share one error and render stack;
- a layout cycle is an error;
- nested layouts are rejected in version one;
- an included template cannot declare page.

Whether layout templates may be rendered directly is unresolved. The current proposal permits it: yields use their default or an empty fragment when no page frame exists.

## 19. Include scoping rules

Includes are lexically isolated.

~~~opia
<include
    view="users.card"
    user="user"
    showEmail="viewer.canSeeEmail"
/>
~~~

Rules:

- view is a required static logical name;
- view is not an expression and cannot be selected from runtime data;
- all other attributes are named arguments;
- each argument value is an expression evaluated in the caller;
- the included root scope contains only explicitly passed arguments;
- caller variables are not inherited implicitly;
- included templates cannot mutate caller data;
- duplicate argument names are errors;
- view is reserved and cannot also be an argument;
- argument names use the identifier grammar;
- an included template may include another template;
- include cycles are rejected with the complete chain;
- included output is an internal safe fragment because it was rendered by Opia;
- registered functions remain available because they belong to the rendering environment, not variable scope;
- layouts cannot be activated from an included template in version one.

There are no implicit global, container, request, session, or environment variables.

## 20. Error messages and source locations

Every compile or render error must include structured context:

- stable error code;
- logical template name;
- source path for trusted logs and development output;
- 1-based line;
- 1-based Unicode column;
- concise message;
- source excerpt and caret in development;
- include and layout stack when relevant;
- expression or structural element responsible;
- original exception as a cause when application code failed.

Proposed development form:

~~~text
users/index.opia:14:9 [OPIA-E231]
Undefined value "user.profile.name".
    [[ user.profile.name ]]
       ^
Included from dashboard.opia:8:5.
~~~

Diagnostics must not include runtime values, raw HTML, passwords, session identifiers, environment values, or function arguments.

Suggested categories:

- OPIA-Pxxx: tokenization and parsing;
- OPIA-Sxxx: structural and nesting validation;
- OPIA-Exxx: expression compile errors;
- OPIA-Rxxx: render and data errors;
- OPIA-Cxxx: cache and dependency errors.

Exact codes require a separate error catalogue before implementation.

## 21. Contextual escaping boundaries

Version one supports exactly two output boundaries:

1. escaped HTML text through [[ expression ]];
2. trusted HTML fragment insertion through raw and internal include/layout fragments.

It does not support expression output in:

- quoted or unquoted attributes;
- href, src, action, style, or other URL-bearing attributes;
- event-handler attributes;
- class or id attributes;
- script;
- style;
- JSON script blocks;
- comments;
- tag or attribute names.

Future contextual attributes are reserved:

~~~opia
<a bind:href="profileUrl">
<button use:disabled="saving">
<div class:error="hasErrors">
~~~

Proposed meanings are intentionally not finalized. Future implementations must define context-specific encoding and validation. For example, URL binding requires a URL policy rather than HTML escaping alone, boolean attributes require presence semantics, and class toggles require token validation.

The reserved attribute namespaces are:

- bind:
- use:
- class:
- on:
- opia:

Any such attribute is rejected in version one so it cannot silently change meaning after an upgrade.

## 22. Template cache and invalidation expectations

An implementation may interpret an AST or compile to cache files. It must not use eval().

A cache entry should be keyed by at least:

- canonical source identity;
- source content hash;
- Opia language version;
- compiler/runtime version;
- relevant compile options;
- registered function-name signature;
- PHP compatibility target.

Static include and layout dependencies must be recorded. A changed, added, removed, or retargeted dependency invalidates dependents.

Cache requirements:

- writes are atomic;
- partially written entries are never executed;
- cache files are not writable by untrusted web users;
- cache metadata is validated before loading;
- source paths cannot select files outside configured roots;
- stale entries cannot silently survive a language-version change;
- cache clearing can be performed explicitly during deployment;
- production precompilation should fail deployment on any template error.

A future PHP compiler may write deterministic PHP cache files and load them with require. Generated code must not contain source-derived executable PHP fragments. No template expression is copied as PHP source.

## 23. Development versus production behavior

Development mode should:

- parse or validate changed templates automatically;
- verify dependency freshness;
- show detailed safe diagnostics and excerpts;
- include template stacks;
- warn about reserved future syntax and suspicious delimiters in unsupported contexts;
- avoid serving a previously cached template after a new source compile fails unless the developer explicitly opts into that behavior.

Production mode should:

- prefer precompiled or validated caches;
- return a generic application error to the client;
- log structured template diagnostics without runtime secrets;
- avoid source excerpts in client responses;
- never fall back to arbitrary PHP templates;
- never expose absolute source paths to clients;
- fail closed when cache integrity is uncertain.

Debug mode changes diagnostics, not language semantics.

## 24. Backward compatibility policy

The language has its own version independent of the framework package version.

Before Opia 1.0, this RFC may change after explicit review. After 1.0:

- patch releases may fix diagnostics and implementation defects without changing valid template meaning;
- minor releases may add syntax only inside already reserved namespaces or in forms previously rejected;
- a minor release must not reinterpret a template that version one accepted as ordinary output;
- new bare structural tags require a major language version;
- removal or semantic change of existing syntax requires a major language version;
- deprecated syntax must produce a development warning for at least one minor cycle when safe;
- compiled cache entries record the language version;
- applications may pin a language version during migration.

Unknown bare custom elements pass through, so the reserved bare-tag list is closed for the 1.x line.

## 25. Reserved tags and attribute namespaces

Reserved bare tags for 1.x:

~~~text
raw when elsewhen otherwise each none page slot yield include
~~~

Reserved element namespace:

~~~text
opia:
~~~

Reserved attribute namespaces:

~~~text
bind: use: class: on: opia:
~~~

Reserved identifiers include expression keywords and implementation-only names beginning with two underscores.

Ordinary custom elements remain valid when they do not collide. Standard browser custom-element names normally contain a hyphen, which reduces but does not remove collision risk.

A template using a reserved tag for an unrelated web component must rename that component or wait for a namespace decision. Opia does not guess whether when means a structural element or a browser custom element.

## 26. Security threat model

Opia assumes:

- templates are trusted application source reviewed like code;
- template data may be attacker-controlled;
- registered functions are trusted application code;
- the template cache and deployment filesystem are security boundaries.

Opia aims to prevent:

- HTML injection through normal output;
- accidental raw output;
- arbitrary PHP execution;
- environment and service-container discovery;
- static and object method execution from expressions;
- template path traversal;
- include and layout cycles;
- second-pass template injection through raw HTML;
- silent undefined-value behavior;
- cache source confusion;
- client disclosure of render data through errors.

Opia does not by itself prevent:

- malicious behavior inside a registered function;
- unsafe HTML deliberately wrapped as trusted;
- denial of service from extremely large templates or iterables without configured limits;
- business-logic authorization errors;
- unsafe JavaScript, CSS, or URLs written literally by a template author;
- secrets deliberately passed into template data;
- filesystem compromise;
- unsafe application logging.

Implementations should set limits for source size, AST depth, expression depth, include depth, layout depth, output size, function calls, and loop iterations. Exact defaults require operational review.



## 27. Examples of valid templates

### 27.1 Conditional list

~~~opia
<when test="viewer.active">
    <h1>Users</h1>

    <each items="users" as="user" key="userKey">
        <article data-user-key="static-value">
            <h2>[[ user.name ]]</h2>
            <p>[[ formatDate(user.createdAt) ]]</p>
            <include view="users.badge" user="user" />
        </article>

        <none>
            <p>No users found.</p>
        </none>
    </each>

    <otherwise>
        <p>Your account is inactive.</p>
    </otherwise>
</when>
~~~

### 27.2 Null handling

~~~opia
<p>[[ user.displayName ?? "Anonymous" ]]</p>
~~~

The displayName key must exist. Its value may be null.

### 27.3 Trusted HTML

~~~opia
<section>
    <raw value="sanitizedArticleBody" />
</section>
~~~

sanitizedArticleBody must be a trusted-HTML capability, not a string.

### 27.4 Layout page

~~~opia
<page layout="app">
    <slot name="title">Account</slot>

    <main>
        <h1>[[ user.name ]]</h1>
    </main>
</page>
~~~

### 27.5 JavaScript, CSS, and JSON remain literal

~~~opia
<script>
    const matrix = [[1, 2], [3, 4]];
    const state = {"items": [["a"], ["b"]]};
</script>

<style>
    input[name="items[]"] { display: block; }
</style>
~~~

Opia does not recognize output delimiters inside script or style raw text. A .opia file is still an HTML template; pure JSON templating is outside scope.

### 27.6 Literal delimiters in visible text

Single square brackets require no escaping:

~~~opia
<p>Use array[index] to select an item.</p>
~~~

To display the literal sequence [[ in an ordinary text node, encode at least one opening bracket as an HTML character reference:

~~~opia
<code>&#91;[ user.name ]]</code>
~~~

The browser displays [[ user.name ]], while the Opia source does not contain the opener.

Documentation showing whole Opia examples should HTML-encode the opener when that documentation is itself rendered by Opia.

### 27.7 Ordinary custom elements and nesting

~~~opia
<user-card>
    <when test="user.active">
        <status-badge>Active</status-badge>
    </when>
</user-card>
~~~

Ordinary custom elements pass through when balanced and not reserved. Opia parses its own nested structures before a browser sees the rendered document.

## 28. Examples that must be rejected

### 28.1 Arbitrary PHP

~~~opia
<?php echo $user->name; ?>
~~~

Reason: PHP processing instructions and PHP blocks are forbidden.

### 28.2 Object and static method calls

~~~opia
[[ user.displayName() ]]
[[ User::find(1) ]]
~~~

Reason: object and static method calls are not in the grammar.

### 28.3 Container or environment access

~~~opia
[[ container("mailer") ]]
[[ env("APP_KEY") ]]
~~~

Reason: unregistered functions fail, and these capabilities must never be registered as generic template escape hatches.

### 28.4 Unsafe attribute interpolation

~~~opia
<a href="[[ profileUrl ]]">Profile</a>
~~~

Reason: version one has no HTML-attribute or URL interpolation context.

### 28.5 Automatic raw strings

~~~opia
<raw value="request.body" />
~~~

Reason: a plain string cannot cross the trusted-HTML boundary.

### 28.6 Silent undefined fallback

~~~opia
[[ missingValue ?? "fallback" ]]
~~~

Reason: missingValue is undefined, not null.

### 28.7 Invalid branch ownership

~~~opia
<elsewhen test="ready">Ready</elsewhen>
<otherwise>Not ready</otherwise>
~~~

Reason: branch markers require a direct owning when.

### 28.8 Invalid branch order

~~~opia
<when test="ready">
    Ready
    <otherwise>Not ready</otherwise>
    <elsewhen test="pending">Pending</elsewhen>
</when>
~~~

Reason: otherwise must be the final branch.

### 28.9 Invalid empty branch ownership

~~~opia
<none>No rows</none>
~~~

Reason: none requires an owning each.

### 28.10 Invalid layout and slot placement

~~~opia
<div>
    <slot name="title">Title</slot>
</div>
~~~

Reason: slot must be a direct child of page.

### 28.11 Dynamic template lookup

~~~opia
<include view="[[ selectedView ]]" user="user" />
<page layout="[[ layoutName ]]">Body</page>
~~~

Reason: include and layout names are static logical-name literals. Output delimiters are forbidden in both attributes.

### 28.12 Malformed structural closing tag

~~~opia
<when test="ready">
    Ready
</each>
~~~

Reason: Opia does not use browser error recovery.

### 28.13 Reserved future syntax

~~~opia
<opia:button>Save</opia:button>
<a bind:href="profileUrl">Profile</a>
~~~

Reason: component and dynamic-attribute namespaces are reserved but unavailable in version one.

### 28.14 Non-boolean condition

~~~opia
<when test="user.name">Active</when>
~~~

Reason: the test result is a string, not a boolean.

### 28.15 Cross-branch HTML

~~~opia
<when test="show">
    <div>
    <otherwise>
        Hidden
    </otherwise>
</when>
</div>
~~~

Reason: ordinary HTML cannot open in one structural branch and close outside it.

### 28.16 Case and component collisions

~~~opia
<When test="ready">Ready</When>
<when>Browser component content</when>
~~~

Reason: case variants of reserved tags are rejected, and the lowercase bare name is owned by Opia rather than an ordinary custom element.

## 29. Ambiguities requiring a final decision

The following questions remain open and require explicit approval before implementation:

1. Bare-tag collision: keep the proposed beginner-friendly bare tags, or namespace every built-in as opia:when, opia:each, and so on?
2. Literal delimiter ergonomics: rely on HTML character references for visible [[ text, or add a verbatim element or escape sequence?
3. Attribute detection: make [[ inside ordinary attribute values a hard error as proposed, or preserve it literally with a development warning?
4. HTML strictness: reject omitted optional end tags and all mismatched ordinary HTML, or validate only Opia structural nesting and leave ordinary HTML recovery to browsers?
5. Object data model: allow declared public properties as proposed, require arrays only, or define a dedicated template-data access contract?
6. Function baseline: ship no built-ins, or standardize a minimal pure set such as count and format helpers?
7. Equality: permit numeric comparison between integers and decimals as proposed, or require identical scalar types?
8. Arithmetic: retain arithmetic operators, or keep version-one expressions limited to boolean and comparison operations?
9. Iterable policy: permit every Traversable, or require arrays plus an explicit safe-iterable contract?
10. Resource budgets: choose default limits for loop iterations, include depth, AST depth, output bytes, and function calls.
11. Loop shadowing: reject every visible-name collision as proposed, or permit explicit lexical shadowing?
12. Layout depth: keep one layout hop in version one, or specify nested layout slot forwarding now?
13. Direct layout rendering: allow yields to use defaults without a page frame, or reject direct rendering of layout templates?
14. Yield defaults: keep the default attribute as escaped plain text, or support fallback child fragments?
15. Trusted HTML API: approve the capability's PHP contract, name, constructors, and sanitizer integration.
16. Whitespace: enable standalone structural-line trimming by default, make it configurable, or preserve every source byte?
17. Null and boolean output: retain strict output errors, or define canonical text rendering?
18. Template roots: support one root only, or specify package namespaces and override order before 1.0?
19. Component collision policy: decide whether future component names are case-sensitive and how they map to PHP classes or registries.
20. Dynamic attribute policy: define URL schemes, boolean presence, class-token validation, and event-handler prohibition before enabling any reserved namespace.

These are design risks, not missing implementation details. The language should not be declared stable until they are resolved.

## 30. Phased implementation roadmap

No phase is implemented by this RFC.

### Phase 0: RFC approval

- resolve the decisions in section 29;
- assign an Opia language version;
- approve the error-code catalogue;
- approve operational resource limits;
- define the trusted-HTML PHP capability.

### Phase 1: tokenizer and source model

- UTF-8 validation;
- HTML-aware tokenizer states;
- raw-text handling for script and style;
- output delimiters in permitted text contexts;
- exact source spans;
- structural tag and reserved-namespace recognition;
- no regex-only compilation.

### Phase 2: structural parser and AST

- balanced ordinary and structural elements;
- context-sensitive nesting validation;
- standalone-line whitespace rules;
- dependency extraction;
- immutable AST nodes;
- rejected-template test corpus.

### Phase 3: expression parser

- dedicated lexer;
- precedence parser;
- literal validation;
- property and index AST;
- registered direct-function calls;
- compile-time rejection of calls, mutation, PHP syntax, and unsupported operators.

### Phase 4: safe interpreter

- strict value model;
- HTML text escaping;
- undefined and null errors;
- condition evaluation;
- arrays and approved iterables;
- request-local execution state;
- resource budgets;
- no eval().

### Phase 5: raw capability and composition

- trusted-HTML boundary;
- includes with isolated scope;
- one-hop layouts;
- slots, yields, and internal safe fragments;
- dependency-cycle diagnostics.

### Phase 6: cache and production tooling

- deterministic cache format;
- content and dependency hashes;
- atomic cache writes;
- development invalidation;
- production precompile and clear commands;
- integrity and version checks;
- generic production errors and structured logs.

### Phase 7: Meulah integration

- introduce a separate Opia renderer or contract;
- keep Meulah\View\View unchanged;
- register Opia explicitly through application bootstrap;
- add framework-level integration tests;
- document migration without automatic .php fallback.

### Phase 8: future RFCs

Separate RFCs must precede:

- opia: components;
- bind: contextual attributes;
- use: boolean attributes;
- class: token toggles;
- any event attribute model;
- nested layouts;
- package template roots;
- additional expression functions or operators.

Components and dynamic attributes must not be implemented merely because their namespaces are reserved.


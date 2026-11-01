{{--
    Обуки: printable document of one group (step 11).

    Pdf::loadView('pdf.edu-group', ['group' => $data, 'printed_at' => '01.06.2026'])->setPaper('a4')

    $group: a plain array, every key optional unless marked:
      program_title   string   the program's full title (required)
      topic           ?string  topic name, printed small under the title
      number          int      group number → "Група {number}" (required)
      staff_label     ?string  e.g. "Стручни соработници"
      location        ?string  place name (location.name ?? location_text)
      address         ?string
      city            ?string  e.g. "Велес"
      language        ?string  e.g. "мк" / "Македонски"
      trainer         ?string  full_name_sq for sq groups when set, else full_name
      dates           ?string  DateRangeFormatter::sessions()['dates'], e.g. "01-02.06.2026"
      time            ?string  DateRangeFormatter::sessions()['time'], e.g. "8.30-13.30 (два дена)"
      mode            ?string  'persons' | 'counts'; default 'persons' when participants is not empty
      participants    list<array{name: string, school?: ?string, municipality?: ?string, email?: ?string, phone?: ?string}>
      schools         list<array{school: string, municipality?: ?string, count: int, count_label?: ?string}>
                      (counts mode; count_label overrides the printed count, e.g. "1+1т")
      signature_days  list<string>  one empty "Потпис {day}" column per entry, e.g. ['01.06', '02.06']
                      (DateRangeFormatter::dayMonth()); [] = no signature columns
      blank_rows      int      counts mode: number of empty numbered rows for names to be written in
      total           ?int     defaults to count(participants) / sum of schools[].count

      bilingual       ?bool    Albanian group printed with "мк / sq" labels
      labels          ?array   GroupDocumentLabels::make($bilingual); default the Macedonian ones

    printed_at        ?string  date in the footer; defaults to today (d.m.Y)
--}}
@php($labels = $group['labels'] ?? \App\Support\Edu\GroupDocumentLabels::make(false))
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="UTF-8">
    <title>{{ $group['program_title'] ?? 'Обука' }} — {{ \App\Support\Edu\GroupDocumentLabels::number($labels['group'], $group['number'] ?? '') }}</title>
    @include('pdf.partials.edu-group-styles')
</head>
<body>
    @include('pdf.partials.edu-group-footer', ['labels' => $labels])
    <div class="group">
        @include('pdf.partials.edu-group', ['group' => $group])
    </div>
</body>
</html>

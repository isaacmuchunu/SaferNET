import { ListChecksIcon, ShieldAlertIcon } from 'lucide-react';
import { PageHeader } from '../components/PageHeader';
import { Cell, DataTable, EmptyState, Panel, PanelHeader, Row } from '../components/Primitives';
import { StatusPill } from '../components/StatusPill';
import { useContentCategories } from '../lib/queries';
import { SEVERITY } from '../lib/domain';

export function CategoriesPage() {
    const categories = useContentCategories();
    const rows = categories.data?.data ?? [];
    const highRisk = rows.filter((category) => category.is_high_risk).length;

    return (
        <div className="animate-fade-up">
            <PageHeader
                title="Content Categories"
                description="The national category set every filtering policy is written against."
                meta={`${rows.length} categories · ${highRisk} high risk`}
            />

            <Panel>
                <PanelHeader
                    title="Category register"
                    description="Categories are maintained centrally; policies decide the enforcement applied to each."
                />
                <DataTable
                    query={categories}
                    rows={rows}
                    minWidth="680px"
                    columns={['Category', 'Reference', 'Default severity', 'Risk', 'Counts toward incidents']}
                    empty={<EmptyState icon={ListChecksIcon} title="No categories defined" description="The category set has not been seeded." />}
                >
                    {rows.map((category) => (
                        <Row key={category.id}>
                            <Cell bold>{category.name}</Cell>
                            <Cell mono muted>
                                {category.slug}
                            </Cell>
                            <Cell>
                                <StatusPill descriptor={SEVERITY[category.default_severity]} />
                            </Cell>
                            <Cell>
                                {category.is_high_risk ? (
                                    <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-danger">
                                        <ShieldAlertIcon size={13} /> High risk
                                    </span>
                                ) : (
                                    <span className="text-xs text-text-secondary">Standard</span>
                                )}
                            </Cell>
                            <Cell muted>{category.counts_toward_incidents ? 'Yes' : 'No'}</Cell>
                        </Row>
                    ))}
                </DataTable>
            </Panel>
        </div>
    );
}
